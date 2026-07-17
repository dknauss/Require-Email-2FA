<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * The 'wp_mail_from_name' encoder: force_2fa_encode_mail_from_name().
 *
 * Encodes ONLY a non-ASCII display name as an RFC 2047 encoded-word, never an
 * address, and leaves plain-ASCII / empty / non-string values untouched.
 */
final class MailFromNameTest extends TestCase {

	public function test_plain_ascii_name_is_unchanged(): void {
		$this->assertSame( 'Acme Blog', force_2fa_encode_mail_from_name( 'Acme Blog' ) );
	}

	public function test_empty_string_is_unchanged(): void {
		$this->assertSame( '', force_2fa_encode_mail_from_name( '' ) );
	}

	public function test_non_string_is_unchanged(): void {
		$this->assertNull( force_2fa_encode_mail_from_name( null ) );
	}

	public function test_ascii_punctuation_is_not_encoded(): void {
		// Printable ASCII incl. punctuation stays raw — no needless encoding.
		$this->assertSame( "O'Brien & Co. (Team)", force_2fa_encode_mail_from_name( "O'Brien & Co. (Team)" ) );
	}

	public function test_non_ascii_name_is_rfc2047_encoded(): void {
		if ( ! function_exists( 'mb_encode_mimeheader' ) ) {
			$this->markTestSkipped( 'mbstring not available.' );
		}

		$encoded = force_2fa_encode_mail_from_name( 'Naïve Café Team' );

		// Becomes a base64 UTF-8 encoded-word...
		$this->assertStringStartsWith( '=?UTF-8?B?', $encoded );
		// ...that round-trips back to the original display name.
		$this->assertSame( 'Naïve Café Team', mb_decode_mimeheader( $encoded ) );
	}

	public function test_encoded_name_never_contains_a_raw_non_ascii_byte(): void {
		if ( ! function_exists( 'mb_encode_mimeheader' ) ) {
			$this->markTestSkipped( 'mbstring not available.' );
		}

		$encoded = force_2fa_encode_mail_from_name( 'Zürich Studio' );
		$this->assertSame( 0, preg_match( '/[^\x20-\x7E]/', $encoded ), 'Encoded header must be pure ASCII.' );
	}

	public function test_already_encoded_word_is_not_double_encoded(): void {
		// An RFC 2047 encoded-word is plain ASCII, so the non-ASCII check skips it.
		$word = '=?UTF-8?B?WsO8cmljaA==?=';
		$this->assertSame( $word, force_2fa_encode_mail_from_name( $word ) );
	}

	public function test_encoding_disabled_via_filter_passes_through(): void {
		$this->setFilter( 'force_2fa_encode_mail_from_name_enabled', false );
		// Even a non-ASCII name is left untouched when the encoder is turned off.
		$this->assertSame( 'Naïve Café Team', force_2fa_encode_mail_from_name( 'Naïve Café Team' ) );
	}

	public function test_encoding_enabled_by_default(): void {
		$this->assertTrue( force_2fa_mail_from_name_encoding_enabled() );
	}
}
