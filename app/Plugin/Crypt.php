<?php
/**
 * File with the handler for any crypt tasks in this plugin
 * We use the composer package "Crypt for WordPress" as help for these tasks.
 *
 * @package threadi-now-pages
 */

declare(strict_types=1);

namespace ThreadiNowPages\Plugin;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

use CryptForWordPress\Method_Base;

/**
 * Object to handle any crypt tasks in this plugin.
 */
class Crypt {
	/**
	 * The used crypt object.
	 *
	 * @var \CryptForWordPress\Crypt|null
	 */
	private ?\CryptForWordPress\Crypt $crypt_obj = null;

	/**
	 * Instance of this object.
	 *
	 * @var ?Crypt
	 */
	private static ?Crypt $instance = null;

	/**
	 * Constructor for this object.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): Crypt {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Return the crypt object with its configuration for this plugin.
	 *
	 * @return \CryptForWordPress\Crypt
	 */
	private function get_crypt_obj(): \CryptForWordPress\Crypt {
		if ( null === $this->crypt_obj ) {
			// configure the crypt object.
			$this->crypt_obj = new \CryptForWordPress\Crypt( NOWPAGES_PLUGIN );
			$this->crypt_obj->set_slug( 'threadi-now-pages' );
			$this->crypt_obj->set_config(
				array(
					'openssl' => array(
						'hash_type'        => 'hash_pbkdf2',
						'hash_algorithm'   => 'sha256',
						'cipher_algorithm' => 'chacha20-poly1305',
					),
					'sodium'  => array(
						'hash_type' => 'sodium_crypto_secretbox_keygen',
					),
				)
			);
		}

		// return the crypt object.
		return $this->crypt_obj;
	}

	/**
	 * Encrypt a given string.
	 *
	 * @param string $plain_text The plain string.
	 * @param string $context The context.
	 *
	 * @return string
	 */
	public function encrypt( string $plain_text, string $context = '' ): string {
		return $this->get_crypt_obj()->encrypt( $plain_text, $context );
	}

	/**
	 * Decrypt a given string.
	 *
	 * @param string $encrypted_string The encrypted string.
	 * @param string $context The context.
	 *
	 * @return string
	 */
	public function decrypt( string $encrypted_string, string $context = '' ): string {
		return $this->get_crypt_obj()->decrypt( $encrypted_string, $context );
	}

	/**
	 * Return the used method.
	 *
	 * @return Method_Base|false
	 */
	public function get_method(): Method_Base|false {
		return $this->get_crypt_obj()->get_method();
	}

	/**
	 * Return the callback to encrypt the value of a setting, bound to a context.
	 *
	 * @param string $context The context, e.g. the name of the setting.
	 *
	 * @return \Closure
	 */
	public function get_save_callback( string $context ): \Closure {
		return fn( $value ) => $this->encrypt( (string) $value, $context );
	}

	/**
	 * Return the callback to decrypt the value of a setting, bound to a context.
	 *
	 * @param string $context The context, e.g. the name of the setting.
	 *
	 * @return \Closure
	 */
	public function get_read_callback( string $context ): \Closure {
		return function ( $value ) use ( $context ) {
			$value = (string) $value;
			if ( '' === $value ) {
				return '';
			}

			$plain = $this->decrypt( $value, $context );

			// transition: the value has been encrypted before the context existed.
			return '' !== $plain ? $plain : $this->decrypt( $value );
		};
	}

	/**
	 * Remove the key and everything the crypt library saved.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		$this->get_crypt_obj()->uninstall();
	}
}
