<?php
/**
 * Tests for Condition_Checker.
 *
 * @package LEAStudios\Snippets\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Tests;

use LEAStudios\Snippets\Execution\Condition_Checker;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Snippets\Execution\Condition_Checker
 */
class ConditionCheckerTest extends TestCase {

	private Condition_Checker $checker;

	public function set_up(): void {
		parent::set_up();
		$this->checker = new Condition_Checker();
	}

	public function test_empty_conditions_returns_true(): void {
		$this->assertTrue( $this->checker->check( [] ) );
	}

	public function test_non_array_conditions_are_skipped(): void {
		$this->assertTrue( $this->checker->check( [ 'not_an_array', 42, null ] ) );
	}

	public function test_unknown_condition_type_returns_true(): void {
		$conditions = [
			[
				'type'     => 'some_unknown_type',
				'value'    => 'anything',
				'operator' => 'is',
			],
		];

		$this->assertTrue( $this->checker->check( $conditions ) );
	}

	public function test_user_logged_in_condition_with_logged_out_user(): void {
		wp_set_current_user( 0 );

		$conditions = [
			[
				'type'     => 'user_logged_in',
				'value'    => '',
				'operator' => 'is',
			],
		];

		$this->assertFalse( $this->checker->check( $conditions ) );
	}

	public function test_user_logged_in_condition_with_logged_in_user(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$conditions = [
			[
				'type'     => 'user_logged_in',
				'value'    => '',
				'operator' => 'is',
			],
		];

		$this->assertTrue( $this->checker->check( $conditions ) );
	}

	/**
	 * The legacy 'user_logged' type should work identically to 'user_logged_in'.
	 */
	public function test_user_logged_alias_works(): void {
		wp_set_current_user( 0 );

		$conditions = [
			[
				'type'     => 'user_logged',
				'value'    => '',
				'operator' => 'is',
			],
		];

		$this->assertFalse( $this->checker->check( $conditions ) );
	}

	public function test_is_not_operator_negates_result(): void {
		wp_set_current_user( 0 );

		$conditions = [
			[
				'type'     => 'user_logged_in',
				'value'    => '',
				'operator' => 'is_not',
			],
		];

		// User is NOT logged in, and operator is 'is_not', so the result should be true.
		$this->assertTrue( $this->checker->check( $conditions ) );
	}

	public function test_user_role_condition(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$passes = [
			[
				'type'     => 'user_role',
				'value'    => 'editor',
				'operator' => 'is',
			],
		];

		$fails = [
			[
				'type'     => 'user_role',
				'value'    => 'administrator',
				'operator' => 'is',
			],
		];

		$this->assertTrue( $this->checker->check( $passes ) );
		$this->assertFalse( $this->checker->check( $fails ) );
	}

	public function test_user_role_returns_false_when_logged_out(): void {
		wp_set_current_user( 0 );

		$conditions = [
			[
				'type'     => 'user_role',
				'value'    => 'administrator',
				'operator' => 'is',
			],
		];

		$this->assertFalse( $this->checker->check( $conditions ) );
	}

	public function test_and_logic_requires_all_conditions(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		// First condition passes (user is logged in), second fails (wrong role).
		$conditions = [
			[
				'type'     => 'user_logged_in',
				'value'    => '',
				'operator' => 'is',
			],
			[
				'type'     => 'user_role',
				'value'    => 'administrator',
				'operator' => 'is',
			],
		];

		$this->assertFalse( $this->checker->check( $conditions ) );
	}

	public function test_default_operator_is_is(): void {
		wp_set_current_user( 0 );

		// No operator key — should default to 'is'.
		$conditions = [
			[
				'type'  => 'user_logged_in',
				'value' => '',
			],
		];

		$this->assertFalse( $this->checker->check( $conditions ) );
	}

	public function test_condition_result_filter(): void {
		wp_set_current_user( 0 );

		// Override the condition result via filter.
		add_filter(
			'leastudios_snippets_condition_result',
			function () {
				return true;
			}
		);

		$conditions = [
			[
				'type'     => 'user_logged_in',
				'value'    => '',
				'operator' => 'is',
			],
		];

		$this->assertTrue( $this->checker->check( $conditions ) );
	}
}
