<?php
/**
 * AI provider clients. Common interface; one class per vendor.
 * chat() returns a normalized array:
 *   [ 'type' => 'tool_calls', 'calls' => [ ['id'=>..,'name'=>..,'args'=>array], ... ], 'raw_assistant' => mixed ]
 *   [ 'type' => 'text', 'text' => string ]
 *   [ 'type' => 'error', 'error' => string ]
 *
 * Message format used by the orchestrator (provider-neutral):
 *   [ 'role' => 'user'|'assistant'|'tool', 'content' => string,
 *     'tool_call_id' => ?string, 'name' => ?string,
 *     'tool_calls' => ?array ]   // assistant turns that requested tools
 *
 * @package AnchorTools (Page Editor module; ported from Anchor Framework 1.2.1 inc/ai-providers.php)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Anchor_PE_AI_Provider {
	/**
	 * @param string $system System prompt.
	 * @param array  $messages Provider-neutral message list (see file docblock).
	 * @param array  $tools anchor_pe_ai_tools() registry.
	 * @return array Normalized response.
	 */
	public function chat( $system, $messages, $tools );
}

/**
 * Factory: build the active provider client, or null.
 *
 * @return Anchor_PE_AI_Provider|null
 */
function anchor_pe_ai_make_provider() {
	$s = get_option( 'ac_ai_settings', array() );
	$active = isset( $s['active'] ) ? $s['active'] : '';
	if ( empty( $active ) || empty( $s[ $active ]['api_key'] ) ) {
		return null;
	}
	$key   = $s[ $active ]['api_key'];
	$model = ! empty( $s[ $active ]['model'] ) ? $s[ $active ]['model'] : '';
	switch ( $active ) {
		case 'anthropic':
			return new Anchor_PE_AI_Anthropic( $key, $model ? $model : 'claude-sonnet-4-6' );
		case 'openai':
			return new Anchor_PE_AI_OpenAI( $key, $model ? $model : 'gpt-4o' );
		case 'gemini':
			return new Anchor_PE_AI_Gemini( $key, $model ? $model : 'gemini-2.0-flash' );
	}
	return null;
}

/**
 * Shared HTTP POST helper.
 *
 * @param string $url URL.
 * @param array  $headers Headers.
 * @param array  $body Body (encoded to JSON).
 * @return array|WP_Error decoded array or WP_Error.
 */
function anchor_pe_ai_post_json( $url, $headers, $body ) {
	$resp = wp_remote_post(
		$url,
		array(
			'timeout' => 60,
			'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return $resp;
	}
	$code = wp_remote_retrieve_response_code( $resp );
	$data = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( $code < 200 || $code >= 300 ) {
		$msg = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : ( 'HTTP ' . $code );
		return new WP_Error( 'anchor_pe_ai_http', $msg );
	}
	return is_array( $data ) ? $data : array();
}

/**
 * Build the OpenAI/Anthropic-style tools array from the registry.
 * (Anthropic + OpenAI share JSON-Schema params; shapes differ slightly — see each class.)
 */

/* ----------------------------- Anthropic ----------------------------- */
class Anchor_PE_AI_Anthropic implements Anchor_PE_AI_Provider {
	private $key;
	private $model;
	public function __construct( $key, $model ) {
		$this->key   = $key;
		$this->model = $model;
	}
	public function chat( $system, $messages, $tools ) {
		$tool_defs = array();
		foreach ( $tools as $name => $def ) {
			$tool_defs[] = array(
				'name'         => $name,
				'description'  => $def['description'],
				'input_schema' => $def['parameters'],
			);
		}
		$body = array(
			'model'      => $this->model,
			'max_tokens' => 4096,
			'system'     => $system,
			'tools'      => $tool_defs,
			'messages'   => $this->to_anthropic_messages( $messages ),
		);
		$data = anchor_pe_ai_post_json(
			'https://api.anthropic.com/v1/messages',
			array(
				'x-api-key'         => $this->key,
				'anthropic-version' => '2023-06-01',
			),
			$body
		);
		if ( is_wp_error( $data ) ) {
			return array( 'type' => 'error', 'error' => $data->get_error_message() );
		}
		$calls = array();
		$text  = '';
		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( 'tool_use' === ( $block['type'] ?? '' ) ) {
					$calls[] = array(
						'id'   => $block['id'] ?? '',
						'name' => $block['name'] ?? '',
						'args' => is_array( $block['input'] ?? null ) ? $block['input'] : array(),
					);
				} elseif ( 'text' === ( $block['type'] ?? '' ) ) {
					$text .= $block['text'] ?? '';
				}
			}
		}
		if ( $calls ) {
			return array( 'type' => 'tool_calls', 'calls' => $calls, 'raw_assistant' => $data['content'] );
		}
		return array( 'type' => 'text', 'text' => $text );
	}
	private function to_anthropic_messages( $messages ) {
		$out = array();
		foreach ( $messages as $m ) {
			if ( 'user' === $m['role'] ) {
				$out[] = array( 'role' => 'user', 'content' => $m['content'] );
			} elseif ( 'assistant' === $m['role'] ) {
				// Replay the assistant's tool_use blocks verbatim if present.
				$out[] = array( 'role' => 'assistant', 'content' => $m['raw_assistant'] ?? $m['content'] );
			} elseif ( 'tool' === $m['role'] ) {
				$out[] = array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'        => 'tool_result',
							'tool_use_id' => $m['tool_call_id'],
							'content'     => $m['content'],
						),
					),
				);
			}
		}
		return $out;
	}
}

/* ------------------------------- OpenAI ------------------------------- */
class Anchor_PE_AI_OpenAI implements Anchor_PE_AI_Provider {
	private $key;
	private $model;
	public function __construct( $key, $model ) {
		$this->key   = $key;
		$this->model = $model;
	}
	public function chat( $system, $messages, $tools ) {
		$tool_defs = array();
		foreach ( $tools as $name => $def ) {
			$tool_defs[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $name,
					'description' => $def['description'],
					'parameters'  => $def['parameters'],
				),
			);
		}
		$msgs = array( array( 'role' => 'system', 'content' => $system ) );
		foreach ( $messages as $m ) {
			if ( 'user' === $m['role'] ) {
				$msgs[] = array( 'role' => 'user', 'content' => $m['content'] );
			} elseif ( 'assistant' === $m['role'] ) {
				$a = array( 'role' => 'assistant', 'content' => isset( $m['content'] ) ? $m['content'] : '' );
				if ( ! empty( $m['tool_calls'] ) ) {
					$a['tool_calls'] = $m['tool_calls'];
					$a['content']    = null;
				}
				$msgs[] = $a;
			} elseif ( 'tool' === $m['role'] ) {
				$msgs[] = array(
					'role'         => 'tool',
					'tool_call_id' => $m['tool_call_id'],
					'content'      => $m['content'],
				);
			}
		}
		$data = anchor_pe_ai_post_json(
			'https://api.openai.com/v1/chat/completions',
			array( 'Authorization' => 'Bearer ' . $this->key ),
			array(
				'model'    => $this->model,
				'messages' => $msgs,
				'tools'    => $tool_defs,
			)
		);
		if ( is_wp_error( $data ) ) {
			return array( 'type' => 'error', 'error' => $data->get_error_message() );
		}
		$msg = $data['choices'][0]['message'] ?? array();
		if ( ! empty( $msg['tool_calls'] ) ) {
			$calls = array();
			foreach ( $msg['tool_calls'] as $tc ) {
				$calls[] = array(
					'id'   => $tc['id'] ?? '',
					'name' => $tc['function']['name'] ?? '',
					'args' => json_decode( $tc['function']['arguments'] ?? '{}', true ) ?: array(),
				);
			}
			return array( 'type' => 'tool_calls', 'calls' => $calls, 'raw_assistant' => $msg['tool_calls'] );
		}
		return array( 'type' => 'text', 'text' => $msg['content'] ?? '' );
	}
}

/* ------------------------------- Gemini ------------------------------- */
class Anchor_PE_AI_Gemini implements Anchor_PE_AI_Provider {
	private $key;
	private $model;
	public function __construct( $key, $model ) {
		$this->key   = $key;
		$this->model = $model;
	}
	public function chat( $system, $messages, $tools ) {
		$decls = array();
		foreach ( $tools as $name => $def ) {
			$decls[] = array(
				'name'        => $name,
				'description' => $def['description'],
				'parameters'  => $def['parameters'],
			);
		}
		$contents = array();
		foreach ( $messages as $m ) {
			if ( 'user' === $m['role'] ) {
				$contents[] = array( 'role' => 'user', 'parts' => array( array( 'text' => $m['content'] ) ) );
			} elseif ( 'assistant' === $m['role'] ) {
				if ( ! empty( $m['raw_assistant'] ) ) {
					$contents[] = array( 'role' => 'model', 'parts' => $m['raw_assistant'] );
				} else {
					$contents[] = array( 'role' => 'model', 'parts' => array( array( 'text' => $m['content'] ) ) );
				}
			} elseif ( 'tool' === $m['role'] ) {
				$contents[] = array(
					'role'  => 'user',
					'parts' => array(
						array(
							'functionResponse' => array(
								'name'     => $m['name'],
								'response' => array( 'result' => $m['content'] ),
							),
						),
					),
				);
			}
		}
		$url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->model ) . ':generateContent?key=' . rawurlencode( $this->key );
		$body = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
			'contents'          => $contents,
			'tools'             => array( array( 'functionDeclarations' => $decls ) ),
		);
		$data = anchor_pe_ai_post_json( $url, array(), $body );
		if ( is_wp_error( $data ) ) {
			return array( 'type' => 'error', 'error' => $data->get_error_message() );
		}
		$parts = $data['candidates'][0]['content']['parts'] ?? array();
		$calls = array();
		$text  = '';
		foreach ( $parts as $p ) {
			if ( isset( $p['functionCall'] ) ) {
				$calls[] = array(
					'id'   => $p['functionCall']['name'] ?? '',
					'name' => $p['functionCall']['name'] ?? '',
					'args' => is_array( $p['functionCall']['args'] ?? null ) ? $p['functionCall']['args'] : array(),
				);
			} elseif ( isset( $p['text'] ) ) {
				$text .= $p['text'];
			}
		}
		if ( $calls ) {
			return array( 'type' => 'tool_calls', 'calls' => $calls, 'raw_assistant' => $parts );
		}
		return array( 'type' => 'text', 'text' => $text );
	}
}
