<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 Thomas Vincent                                       |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cloudflare plugin for Cacti                                             |
 | https://github.com/somethingwithproof/plugin_cloudflare                 |
 +-------------------------------------------------------------------------+
*/

namespace Cacti\PluginCloudflare\Storage;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Thin wrapper over a PDO handle. In production the handle is Cacti's own and
 * is left in ERRMODE_SILENT, so return values and errorInfo() are checked here
 * and no attribute is ever changed on the shared handle.
 */
final class Database {
	/** @var PDO */
	private $pdo;

	/** @var int */
	private $depth = 0;

	public function __construct(PDO $pdo) {
		$this->pdo = $pdo;
	}

	/**
	 * @param array<int|string, mixed> $params
	 * @return int Matched rows (Cacti sets MYSQL_ATTR_FOUND_ROWS).
	 */
	public function execute(string $sql, array $params = []): int {
		return $this->run($sql, $params)->rowCount();
	}

	/**
	 * @param array<int|string, mixed> $params
	 * @return array<int, array<string, mixed>>
	 */
	public function fetchAll(string $sql, array $params = []): array {
		$rows = $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

		return is_array($rows) ? $rows : [];
	}

	/**
	 * @param array<int|string, mixed> $params
	 * @return array<string, mixed>|null
	 */
	public function fetchRow(string $sql, array $params = []): ?array {
		$row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	/**
	 * First column of the first row; null for no row or an SQL NULL.
	 *
	 * @param array<int|string, mixed> $params
	 * @return scalar|null
	 */
	public function fetchCell(string $sql, array $params = []) {
		$value = $this->run($sql, $params)->fetchColumn();

		return $value === false ? null : $value;
	}

	public function lastInsertId(): int {
		return (int) $this->pdo->lastInsertId();
	}

	/**
	 * A nested call joins the outer transaction; only the outermost call
	 * commits or rolls back.
	 *
	 * @param callable $fn
	 * @return mixed
	 */
	public function transactional(callable $fn) {
		if ($this->depth > 0) {
			$this->depth++;

			try {
				return $fn();
			} finally {
				$this->depth--;
			}
		}

		try {
			$started = $this->pdo->beginTransaction();
		} catch (PDOException $e) {
			throw $this->fromPdoException($e);
		}

		if (!$started) {
			throw $this->fromErrorInfo($this->pdo->errorInfo());
		}

		$this->depth = 1;

		try {
			$result = $fn();
		} catch (Throwable $e) {
			$this->depth = 0;

			try {
				if ($this->pdo->inTransaction()) {
					$this->pdo->rollBack();
				}
			} catch (PDOException $rollbackFailure) {
				// The original exception is the one worth reporting.
			}

			throw $e;
		}

		$this->depth = 0;

		try {
			if (!$this->pdo->commit()) {
				throw $this->fromErrorInfo($this->pdo->errorInfo());
			}
		} catch (PDOException $e) {
			throw $this->fromPdoException($e);
		}

		return $result;
	}

	public function acquireLock(string $name, int $timeoutSeconds): bool {
		$result = $this->fetchCell('SELECT GET_LOCK(?, ?)', [$name, $timeoutSeconds]);

		if ($result === null) {
			throw new DatabaseException('HY000', 0);
		}

		return (int) $result === 1;
	}

	public function releaseLock(string $name): void {
		$this->fetchCell('SELECT RELEASE_LOCK(?)', [$name]);
	}

	/**
	 * @param array<int|string, mixed> $params
	 */
	private function run(string $sql, array $params): PDOStatement {
		try {
			$stmt = $this->pdo->prepare($sql);

			if ($stmt === false) {
				throw $this->fromErrorInfo($this->pdo->errorInfo());
			}

			if (!$stmt->execute($params)) {
				throw $this->fromErrorInfo($stmt->errorInfo());
			}
		} catch (PDOException $e) {
			throw $this->fromPdoException($e);
		}

		return $stmt;
	}

	/**
	 * @param array<int, mixed> $info
	 */
	private function fromErrorInfo(array $info): DatabaseException {
		$state = isset($info[0]) ? (string) $info[0] : 'HY000';
		$code = isset($info[1]) ? (int) $info[1] : 0;

		return new DatabaseException($state, $code);
	}

	/* The PDOException is not chained: its message carries the same driver text. */
	private function fromPdoException(PDOException $e): DatabaseException {
		$info = $e->errorInfo;

		if (is_array($info) && isset($info[0])) {
			return $this->fromErrorInfo($info);
		}

		return new DatabaseException('HY000', 0);
	}
}
