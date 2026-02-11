<?php
/**
 * Standalone WordPress Password Hasher (PHPass)
 * A simplified version to verify WordPress hashes without the full library.
 */
class PasswordHash {
	private $itoa64;
	private $iteration_count_log2;
	private $random_state;

	public function __construct($iteration_count_log2, $portable_hashes) {
		$this->itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
		$this->iteration_count_log2 = $iteration_count_log2;
		$this->random_state = microtime() . getmypid();
	}

	public function CheckPassword($password, $stored_hash) {
		if (strlen($stored_hash) < 32) return false;
		
		// If it's a modern bcrypt hash (starting with $2y$), we can use native password_verify
		if (substr($stored_hash, 0, 4) === '$2y$') {
			return password_verify($password, $stored_hash);
		}

		// WordPress portable hash
		if (substr($stored_hash, 0, 3) !== '$P$' && substr($stored_hash, 0, 3) !== '$H$') return false;

		$count_log2 = strpos($this->itoa64, $stored_hash[3]);
		if ($count_log2 < 7 || $count_log2 > 30) return false;

		$count = 1 << $count_log2;
		$salt = substr($stored_hash, 4, 8);
		if (strlen($salt) !== 8) return false;

		$hash = md5($salt . $password, true);
		do {
			$hash = md5($hash . $password, true);
		} while (--$count);

		$output = substr($stored_hash, 0, 12);
		$output .= $this->encode64($hash, 16);

		return $output === $stored_hash;
	}

	private function encode64($input, $count) {
		$output = '';
		$i = 0;
		do {
			$value = ord($input[$i++]);
			$output .= $this->itoa64[$value & 0x3f];
			if ($i < $count) $value |= ord($input[$i]) << 8;
			$output .= $this->itoa64[($value >> 6) & 0x3f];
			if ($i++ >= $count) break;
			if ($i < $count) $value |= ord($input[$i]) << 16;
			$output .= $this->itoa64[($value >> 12) & 0x3f];
			if ($i++ >= $count) break;
			$output .= $this->itoa64[($value >> 18) & 0x3f];
		} while ($i < $count);

		return $output;
	}
}
