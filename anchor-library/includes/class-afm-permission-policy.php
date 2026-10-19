<?php
if (!defined('ABSPATH') && PHP_SAPI !== 'cli') exit;

class Anchor_FM_Permission_Policy {

    public static function empty_policy() {
        return [
            'operator' => 'any',
            'rules' => [],
        ];
    }

    /**
     * Canonical shape of a stored policy, for evaluation and display.
     *
     * Never drops a condition or a rule. Dropping a conjunct from an "all"
     * rule widens it — (premium AND 2026) would become just (2026), i.e.
     * everyone — so anything unusable becomes a {type: never} condition that
     * evaluates false, and a role that no longer exists stays a role
     * condition nobody can satisfy. Writes are validated separately by
     * validate_for_write(), which rejects bad input instead of simplifying it.
     */
    public static function normalize($policy) {
        if (is_string($policy)) {
            $decoded = json_decode($policy, true);
            $policy = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($policy)) {
            $policy = [];
        }

        $normalized = [
            'operator' => self::normalize_operator(isset($policy['operator']) ? $policy['operator'] : 'any'),
            'rules' => [],
        ];

        $rules = isset($policy['rules']) && is_array($policy['rules']) ? $policy['rules'] : [];
        foreach ($rules as $rule) {
            $rule = is_array($rule) ? $rule : [];
            $conditions = [];
            $raw_conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
            foreach ($raw_conditions as $condition) {
                $conditions[] = self::normalize_condition($condition);
            }
            if (!$conditions) {
                $conditions[] = ['type' => 'never'];
            }
            $normalized['rules'][] = [
                'operator' => self::normalize_operator(isset($rule['operator']) ? $rule['operator'] : 'all'),
                'conditions' => $conditions,
            ];
        }

        return $normalized;
    }

    /**
     * Check a policy an administrator is saving. Returns
     * ['ok' => bool, 'error' => string, 'policy' => normalized]. Any
     * condition that would evaluate as "never" — an unknown role, the
     * administrator role, a blank user or date, an unrecognized type — is an
     * error the admin must fix, not something to quietly remove.
     */
    public static function validate_for_write($policy, array $valid_roles) {
        $valid_map = [];
        foreach ($valid_roles as $role) {
            $key = self::sanitize_key($role);
            if ($key !== '' && $key !== 'administrator') $valid_map[$key] = true;
        }

        if (is_string($policy)) {
            $decoded = json_decode($policy, true);
            $policy = is_array($decoded) ? $decoded : [];
        }
        $raw_rules = is_array($policy) && isset($policy['rules']) && is_array($policy['rules']) ? array_values($policy['rules']) : [];

        foreach ($raw_rules as $i => $rule) {
            $n = $i + 1;
            $raw_conditions = is_array($rule) && isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
            if (!$raw_conditions) {
                return self::write_error("Rule {$n} has no conditions.");
            }
            foreach ($raw_conditions as $raw) {
                $type = is_array($raw) && isset($raw['type']) ? strtolower(trim((string) $raw['type'])) : '';
                $condition = self::normalize_condition($raw);
                if ($type === 'role') {
                    $role = isset($raw['role']) ? self::sanitize_key($raw['role']) : '';
                    if ($role === '' || empty($valid_map[$role])) {
                        return self::write_error("Rule {$n} uses a role that no longer exists (" . ($role !== '' ? $role : 'none') . '). Pick another role or remove that condition.');
                    }
                } elseif ($type === 'user' && $condition['type'] === 'never') {
                    return self::write_error("Rule {$n}: choose a user.");
                } elseif ($type === 'date' && $condition['type'] === 'never') {
                    return self::write_error("Rule {$n}: a date range needs a start or end date.");
                } elseif ($condition['type'] === 'never') {
                    return self::write_error("Rule {$n} has a condition that is not recognized. Remove it and save again.");
                }
            }
        }

        return ['ok' => true, 'error' => '', 'policy' => self::normalize($policy)];
    }

    private static function write_error($message) {
        return ['ok' => false, 'error' => $message, 'policy' => self::empty_policy()];
    }

    public static function evaluate($policy, array $context, $now = null) {
        $policy = self::normalize($policy);
        if (empty($policy['rules'])) {
            return false;
        }

        $matches = [];
        foreach ($policy['rules'] as $rule) {
            $matches[] = self::evaluate_rule($rule, $context, $now);
        }

        if ($policy['operator'] === 'all') {
            return !in_array(false, $matches, true);
        }

        return in_array(true, $matches, true);
    }

    /**
     * The policy after $role stops existing, treating each condition on it as
     * always false and simplifying from there. Simply deleting the condition
     * would be wrong: in an "all" rule like (role AND date window) it would
     * leave the date alone and grant everyone during that window.
     */
    public static function without_role($policy, $role) {
        $policy = self::normalize($policy);
        $role = self::sanitize_key($role);
        $rules = [];
        foreach ($policy['rules'] as $rule) {
            $kept = [];
            $had_role = false;
            foreach ($rule['conditions'] as $condition) {
                if ($condition['type'] === 'role' && $condition['role'] === $role) {
                    $had_role = true;
                } else {
                    $kept[] = $condition;
                }
            }
            // all: one false condition makes the rule false. any: false drops out.
            $rule_false = ($had_role && $rule['operator'] === 'all') || !$kept;
            if ($rule_false) {
                if ($policy['operator'] === 'all') {
                    // One false rule makes the whole policy false: grant nobody.
                    return ['operator' => $policy['operator'], 'rules' => []];
                }
                continue;
            }
            $rules[] = ['operator' => $rule['operator'], 'conditions' => $kept];
        }
        return ['operator' => $policy['operator'], 'rules' => $rules];
    }

    public static function rule_count($policy) {
        $policy = self::normalize($policy);
        return count($policy['rules']);
    }

    private static function evaluate_rule(array $rule, array $context, $now = null) {
        $conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
        if (!$conditions) {
            return false;
        }

        $matches = [];
        foreach ($conditions as $condition) {
            $matches[] = self::evaluate_condition($condition, $context, $now);
        }

        $operator = self::normalize_operator(isset($rule['operator']) ? $rule['operator'] : 'all');
        if ($operator === 'any') {
            return in_array(true, $matches, true);
        }

        return !in_array(false, $matches, true);
    }

    private static function evaluate_condition(array $condition, array $context, $now = null) {
        $type = isset($condition['type']) ? (string) $condition['type'] : '';
        if ($type === 'role') {
            $role = isset($condition['role']) ? self::sanitize_key($condition['role']) : '';
            $roles = isset($context['roles']) && is_array($context['roles']) ? $context['roles'] : [];
            $roles = array_map([__CLASS__, 'sanitize_key'], $roles);
            return $role !== '' && in_array($role, $roles, true);
        }

        if ($type === 'user') {
            $user_id = isset($condition['userId']) ? (int) $condition['userId'] : 0;
            $context_user_id = isset($context['userId']) ? (int) $context['userId'] : 0;
            return $user_id > 0 && $user_id === $context_user_id;
        }

        if ($type === 'date') {
            $today = self::normalize_date($now ?: date('Y-m-d'));
            if ($today === '') return false;
            $start = isset($condition['start']) ? self::normalize_date($condition['start']) : '';
            $end = isset($condition['end']) ? self::normalize_date($condition['end']) : '';
            if ($start !== '' && strcmp($today, $start) < 0) return false;
            if ($end !== '' && strcmp($today, $end) > 0) return false;
            return $start !== '' || $end !== '';
        }

        return false;
    }

    /** One condition in canonical form; anything unusable becomes {type: never}. */
    private static function normalize_condition($condition) {
        $never = ['type' => 'never'];
        if (!is_array($condition)) return $never;
        $type = isset($condition['type']) ? strtolower(trim((string) $condition['type'])) : '';

        if ($type === 'role') {
            $role = isset($condition['role']) ? self::sanitize_key($condition['role']) : '';
            // Administrators already hold every capability; as a grant
            // condition the role is meaningless, so it never matches.
            if ($role === '' || $role === 'administrator') return $never;
            return ['type' => 'role', 'role' => $role];
        }

        if ($type === 'user') {
            $user_id = isset($condition['userId']) ? (int) $condition['userId'] : 0;
            if ($user_id <= 0) return $never;
            return ['type' => 'user', 'userId' => (string) $user_id];
        }

        if ($type === 'date') {
            $start = isset($condition['start']) ? self::normalize_date($condition['start']) : '';
            $end = isset($condition['end']) ? self::normalize_date($condition['end']) : '';
            if ($start === '' && $end === '') return $never;
            if ($start !== '' && $end !== '' && strcmp($start, $end) > 0) {
                $tmp = $start;
                $start = $end;
                $end = $tmp;
            }
            return ['type' => 'date', 'start' => $start, 'end' => $end];
        }

        return $never;
    }

    private static function normalize_operator($operator) {
        $operator = strtolower((string) $operator);
        return $operator === 'all' ? 'all' : 'any';
    }

    private static function normalize_date($date) {
        $date = trim((string) $date);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $m)) {
            return $m[1];
        }
        return '';
    }

    private static function sanitize_key($key) {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}
