<?php
/**
 * A minimal $wpdb on in-memory SQLite, for the queue integration tests.
 * Implements only the methods used by the plugin.
 *
 * @package LocationLeadTracker
 */

// phpcs:ignoreFile

final class LLT_Test_WPDB {

	public $prefix     = 'wp_';
	public $options    = 'wp_options';
	public $posts      = 'wp_posts';
	public $insert_id  = 0;
	public $last_error = '';
	/** @var PDO */
	public $pdo;
	/** @var string[] */
	public $log = array();

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
		$this->pdo->exec(
			'CREATE TABLE wp_location_leads (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				object_id INTEGER NOT NULL,
				recipient_email TEXT NOT NULL,
				postmark_message_id TEXT NULL UNIQUE,
				send_status TEXT NOT NULL DEFAULT "pending",
				delivery_status TEXT NOT NULL DEFAULT "unknown",
				retry_count INTEGER NOT NULL DEFAULT 0,
				max_retries INTEGER NOT NULL DEFAULT 5,
				next_retry_at TEXT NULL,
				locked_until TEXT NULL,
				lock_token TEXT NULL,
				last_attempt_at TEXT NULL,
				last_error_code TEXT NULL,
				last_error_message TEXT NULL,
				consent_at TEXT NOT NULL,
				manual_resend_count INTEGER NOT NULL DEFAULT 0,
				last_resend_by INTEGER NULL,
				last_resend_at TEXT NULL,
				dismissed_at TEXT NULL,
				dismissed_by INTEGER NULL,
				created_at TEXT NOT NULL,
				sent_at TEXT NULL,
				delivered_at TEXT NULL,
				updated_at TEXT NOT NULL
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE wp_location_lead_messages (
				lead_id INTEGER PRIMARY KEY,
				sender_name TEXT NOT NULL,
				sender_email TEXT NOT NULL,
				sender_phone TEXT NULL,
				message TEXT NOT NULL,
				created_at TEXT NOT NULL
			)'
		);
		$this->pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_title TEXT)' );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_charset_collate() {
		return '';
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[sdf]/',
			function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ] ?? null;
				if ( '%d' === $m[0] ) {
					return (string) (int) $value;
				}
				if ( '%f' === $m[0] ) {
					return (string) (float) $value;
				}
				return $this->pdo->quote( (string) $value );
			},
			$query
		);
	}

	private function translate( string $sql ): string {
		return preg_replace( '/^\s*START TRANSACTION/i', 'BEGIN', $sql );
	}

	public function query( $sql ) {
		$sql         = $this->translate( $sql );
		$this->log[] = $sql;
		$result      = $this->pdo->exec( $sql );
		if ( false === $result ) {
			$this->last_error = implode( ' ', $this->pdo->errorInfo() );
			return false;
		}
		return $result;
	}

	private function select( string $sql ) {
		$this->log[] = $sql;
		$stmt        = $this->pdo->query( $sql );
		if ( false === $stmt ) {
			$this->last_error = implode( ' ', $this->pdo->errorInfo() );
			throw new RuntimeException( 'Invalid SQL: ' . $this->last_error . ' — ' . $sql );
		}
		return $stmt;
	}

	public function get_results( $sql ) {
		return $this->select( $sql )->fetchAll( PDO::FETCH_OBJ );
	}

	public function get_row( $sql ) {
		$row = $this->select( $sql )->fetch( PDO::FETCH_OBJ );
		return $row ? $row : null;
	}

	public function get_col( $sql ) {
		return $this->select( $sql )->fetchAll( PDO::FETCH_COLUMN, 0 );
	}

	public function get_var( $sql ) {
		$value = $this->select( $sql )->fetchColumn();
		return false === $value ? null : $value;
	}

	public function insert( $table, $data, $format = null ) {
		$columns = array_keys( $data );
		$values  = array_map( array( $this, 'value' ), array_values( $data ) );
		$ok      = $this->query( "INSERT INTO {$table} (" . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ')' );
		if ( false === $ok ) {
			return false;
		}
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}

	public function update( $table, $data, $where ) {
		$set = array();
		foreach ( $data as $column => $value ) {
			$set[] = $column . ' = ' . $this->value( $value );
		}
		$conditions = array();
		foreach ( $where as $column => $value ) {
			$conditions[] = $column . ' = ' . $this->value( $value );
		}
		return $this->query( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $conditions ) );
	}

	public function delete( $table, $where, $format = null ) {
		$conditions = array();
		foreach ( $where as $column => $value ) {
			$conditions[] = $column . ' = ' . $this->value( $value );
		}
		return $this->query( "DELETE FROM {$table} WHERE " . implode( ' AND ', $conditions ) );
	}

	private function value( $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		return $this->pdo->quote( (string) $value );
	}
}
