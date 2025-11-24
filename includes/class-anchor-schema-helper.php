<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Anchor_Schema_Helper {

    public static function get_schema_types(){
        return [
            'Article',
            'BlogPosting',
            'NewsArticle',
            'FAQPage',
            'HowTo',
            'Event',
            'Product',
            'Offer',
            'Review',
            'Service',
            'LocalBusiness',
            'Organization',
            'Person',
            'WebSite',
            'WebPage',
            'AboutPage',
            'ContactPage',
            'VideoObject',
            'ImageObject',
            'Course',
        ];
    }

    public static function build_prompt($type, $raw){
        $type = $type ?: 'Thing';
        $raw  = trim((string) $raw);
        $instructions = "You are a strict JSON-LD generator. Produce only valid JSON, no code fences, no comments. Output a single JSON object for schema.org with @context https://schema.org and @type $type. Infer properties from the provided raw text. Validate against schema.org vocabulary. Use English keys. Do not include HTML. Ensure it can be embedded in a <script type=\"application/ld+json\"> tag.\n\nRaw text:\n$raw";
        return $instructions;
    }

    public static function call_openai($api_key, $model, $prompt){
        Anchor_Schema_Logger::log('openai:request', [ 'model' => $model, 'prompt_len' => strlen($prompt) ]);
        $body = [
            'model' => $model ?: 'gpt-4o-mini',
            'messages' => [
                [ 'role' => 'system', 'content' => 'You generate clean JSON-LD only.' ],
                [ 'role' => 'user', 'content' => $prompt ],
            ],
            'temperature' => 0,
            'max_tokens' => 1200,
        ];
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 60,
        ]);
        if ( is_wp_error($response) ) {
            Anchor_Schema_Logger::log('openai:error', [ 'wp_error' => $response->get_error_message() ]);
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        Anchor_Schema_Logger::log('openai:response', [ 'code' => $code, 'body_preview' => substr($raw, 0, 400) ]);
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error('openai_http', 'OpenAI error ' . $code . ': ' . $raw);
        }
        $data = json_decode($raw, true);
        if ( ! $data || empty($data['choices'][0]['message']['content']) ) {
            return new WP_Error('openai_parse', 'Invalid response from model');
        }
        return $data['choices'][0]['message']['content'];
    }

    public static function extract_json($content){
        $trim = trim($content);
        json_decode($trim);
        if ( json_last_error() === JSON_ERROR_NONE ) { return $trim; }
        if ( preg_match('/\{[\s\S]*\}/', $content, $m) ) { return $m[0]; }
        return '';
    }

    public static function minify_json($json){
        $decoded = json_decode($json, true);
        if ( json_last_error() !== JSON_ERROR_NONE ) { return ''; }
        return wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function validate_schema_json( $content ){
        $errors = [];
        $warnings = [];

        $decoded = json_decode( $content, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $errors[] = 'JSON parse error: ' . json_last_error_msg();
            return [ 'errors' => $errors, 'warnings' => $warnings, 'normalized_json' => '', 'primary_type' => '' ];
        }

        $objects = [];
        if ( is_array($decoded) && isset($decoded['@context']) ) {
            $objects = [ $decoded ];
        } elseif ( is_array($decoded) && array_keys($decoded) === range(0, count($decoded) - 1) ) {
            $objects = $decoded;
        } else {
            $errors[] = 'Expected a JSON object with @context and @type or an array of such objects.';
        }

        $allowed_types = self::get_schema_types();
        $primary_type  = '';

        foreach ( $objects as $idx => &$obj ) {
            if ( ! is_array($obj) ) { $errors[] = 'Item ' . ($idx+1) . ' is not an object'; continue; }
            if ( empty($obj['@context']) ) {
                $warnings[] = 'Item ' . ($idx+1) . ': @context missing, defaulting to https://schema.org';
                $obj['@context'] = 'https://schema.org';
            }
            if ( empty($obj['@type']) ) {
                $errors[] = 'Item ' . ($idx+1) . ': @type is required';
                continue;
            }
            if ( ! $primary_type ) {
                $primary_type = is_array($obj['@type']) ? (string)reset($obj['@type']) : (string)$obj['@type'];
            }
            $tlist = (array)$obj['@type'];
            foreach($tlist as $t){
                if ( ! in_array($t, $allowed_types, true) ) {
                    $warnings[] = 'Item ' . ($idx+1) . ': @type ' . $t . ' not in default list, verify against schema.org.';
                }
            }
        }
        unset($obj);

        if ( ! empty($errors) ) {
            return [ 'errors' => $errors, 'warnings' => $warnings, 'normalized_json' => '', 'primary_type' => $primary_type ];
        }

        $normalized = wp_json_encode( $objects, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( is_array($decoded) && isset($decoded['@context']) ) {
            $normalized = wp_json_encode( $objects[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }

        return [ 'errors' => [], 'warnings' => $warnings, 'normalized_json' => $normalized, 'primary_type' => $primary_type ];
    }
}
