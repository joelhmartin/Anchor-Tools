<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    if (php_sapi_name() !== 'cli') { exit; }
}

/**
 * An in-memory snapshot of the permission tables.
 *
 * The resolver asks the database about one entity at a time: three queries per
 * entity, repeated for every ancestor level, repeated for every row in a
 * listing. Against a store whose entire permissions table is 32 rows, answering
 * "which of these 768 files may this user see" cost 3521 queries. This holds
 * the same rows in arrays so the identical algorithm can run without going back
 * to the database.
 *
 * It is a data structure, not a policy: it answers exactly what the queries it
 * replaces answered, and decides nothing. Capability ranking, ancestor walking
 * and policy evaluation all stay where they were.
 *
 * MATCHING SEMANTICS. Lookups are case- and trailing-space-insensitive because
 * the queries being replaced compared `subject_key` with MySQL `=` and `IN`
 * under a _ci PAD SPACE collation. Mirroring that is the point: a stricter
 * comparison would deny access the site currently grants, and a looser one
 * would grant access it currently denies. Both are wrong.
 */
class Anchor_FM_Permission_Index {

    /** id => ['parent' => int, 'owner' => int] */
    private $folders = [];

    /** "type:id" => [normalized subject key => [capability, ...]] */
    private $user_caps = [];
    private $role_caps = [];

    /** "type:id:capability" => raw policy value */
    private $policies = [];

    /** "type:id" => folder id the entity lives in */
    private $entity_folders = [];

    /** entity types this index holds in full */
    private $tracked = [];

    /** Mirror of MySQL's _ci PAD SPACE comparison for a subject key. */
    public static function normalize_key($key) {
        return strtolower(rtrim((string) $key, ' '));
    }

    private static function entity_key($entity_type, $entity_id) {
        return $entity_type . ':' . (int) $entity_id;
    }

    public function add_folder($id, $parent_id, $owner_user_id) {
        $this->folders[(int) $id] = [
            'parent' => (int) $parent_id,
            'owner'  => (int) $owner_user_id,
        ];
    }

    public function add_permission($entity_type, $entity_id, $subject_type, $subject_key, $capability) {
        $bucket = null;
        if ($subject_type === 'user') {
            $bucket = 'user_caps';
        } elseif ($subject_type === 'role') {
            $bucket = 'role_caps';
        } else {
            // Unknown subject types were never matched by either query, so
            // holding them would grant something the database would not.
            return;
        }

        $ek = self::entity_key($entity_type, $entity_id);
        $sk = self::normalize_key($subject_key);
        $this->{$bucket}[$ek][$sk][] = (string) $capability;
    }

    public function add_policy($entity_type, $entity_id, $capability, $policy) {
        $this->policies[self::entity_key($entity_type, $entity_id) . ':' . $capability] = $policy;
    }

    /** ['parent' => int, 'owner' => int], or null when the folder is unknown. */
    public function folder($id) {
        $id = (int) $id;
        return isset($this->folders[$id]) ? $this->folders[$id] : null;
    }

    public function has_folder($id) {
        return isset($this->folders[(int) $id]);
    }

    /**
     * Every capability granted to this user on this entity.
     *
     * Mirrors: SELECT capability WHERE entity_type=? AND entity_id=?
     *          AND subject_type='user' AND subject_key=?
     * -- note the absence of a capability filter, which is deliberate there.
     */
    public function user_capabilities($entity_type, $entity_id, $user_key) {
        $ek = self::entity_key($entity_type, $entity_id);
        $sk = self::normalize_key($user_key);
        return isset($this->user_caps[$ek][$sk]) ? $this->user_caps[$ek][$sk] : [];
    }

    /**
     * View capabilities granted to any of these roles on this entity.
     *
     * Mirrors: SELECT capability WHERE entity_type=? AND entity_id=?
     *          AND subject_type='role' AND capability='view'
     *          AND subject_key IN (...)
     * -- the capability='view' filter is part of that query and is kept here.
     */
    public function role_view_capabilities($entity_type, $entity_id, array $role_keys) {
        $ek = self::entity_key($entity_type, $entity_id);
        if (empty($this->role_caps[$ek])) return [];

        $out = [];
        foreach ($role_keys as $role) {
            $sk = self::normalize_key($role);
            if (empty($this->role_caps[$ek][$sk])) continue;
            foreach ($this->role_caps[$ek][$sk] as $cap) {
                if ($cap === 'view') $out[] = $cap;
            }
        }
        return $out;
    }

    /** Raw stored policy, or null when the entity has none. */
    public function policy($entity_type, $entity_id, $capability) {
        $k = self::entity_key($entity_type, $entity_id) . ':' . $capability;
        return array_key_exists($k, $this->policies) ? $this->policies[$k] : null;
    }

    /**
     * Which folder an entity lives in, for the inheritance step.
     *
     * A file, link or video inherits from its folder, so resolving one meant a
     * row query per entity purely to read its folder_id -- 902 of the 905
     * queries left after the permission rows moved into memory.
     */
    public function add_entity_folder($entity_type, $entity_id, $folder_id) {
        $this->entity_folders[self::entity_key($entity_type, $entity_id)] = (int) $folder_id;
    }

    /** Folder id, or null when the entity does not exist. */
    public function entity_folder($entity_type, $entity_id) {
        $k = self::entity_key($entity_type, $entity_id);
        return array_key_exists($k, $this->entity_folders) ? $this->entity_folders[$k] : null;
    }

    /**
     * Whether this index holds a complete list of entities of this type.
     *
     * Without it, a missing key is ambiguous: the entity may not exist, or the
     * index may simply not track that type. Reading "does not exist" from the
     * second case would deny access to real files, so callers check this first
     * and fall back to a row query when it is false.
     */
    public function tracks($entity_type) {
        return !empty($this->tracked[$entity_type]);
    }

    public function mark_tracked($entity_type) {
        $this->tracked[$entity_type] = true;
    }

    public function folder_count() {
        return count($this->folders);
    }
}
