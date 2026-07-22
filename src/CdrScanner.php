<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

use PDO;
use Throwable;

final class CdrScanner {
	private const MAX_SCAN_ROWS = 5000;

	private PDO $pdo;
	/** @var callable */
	private $nowProvider;

	public function __construct(PDO $pdo, ?callable $nowProvider = null) {
		$this->pdo = $pdo;
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->nowProvider = $nowProvider ?? function (): string {
			return date('Y-m-d H:i:s');
		};
	}

	public function scanRecentInboundJourneys(int $lookbackMinutes): array {
		if ($lookbackMinutes <= 0 || !$this->tableExists($this->cdrTable()) || !$this->tableExists('incoming')) {
			return [
				'raw_rows' => 0,
				'row_cap_reached' => false,
				'collapsed_rows' => 0,
				'inbound_journeys' => 0,
				'journeys' => [],
			];
		}

		$routes = $this->loadInboundRoutes();
		if (!$routes) {
			return [
				'raw_rows' => 0,
				'row_cap_reached' => false,
				'collapsed_rows' => 0,
				'inbound_journeys' => 0,
				'journeys' => [],
			];
		}

		$now = $this->now();
		$cutoff = date('Y-m-d H:i:s', strtotime($now) - ($lookbackMinutes * 60));
		$columns = $this->tableColumns($this->cdrTable());
		$select = $this->cdrSelectColumns($columns);
		$stmt = $this->pdo->prepare(
			'SELECT ' . implode(', ', $select) . '
			 FROM ' . $this->cdrTable() . '
			 WHERE calldate >= ?
			 ORDER BY calldate DESC, linkedid DESC, uniqueid DESC
			 LIMIT ' . self::MAX_SCAN_ROWS
		);
		$stmt->execute([$cutoff]);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$rowCapReached = count($rows) >= self::MAX_SCAN_ROWS;

		usort($rows, function (array $left, array $right): int {
			$byCallDate = strcmp((string)($left['calldate'] ?? ''), (string)($right['calldate'] ?? ''));
			if ($byCallDate !== 0) {
				return $byCallDate;
			}

			$byLinkedId = strcmp((string)($left['linkedid'] ?? ''), (string)($right['linkedid'] ?? ''));
			if ($byLinkedId !== 0) {
				return $byLinkedId;
			}

			return strcmp((string)($left['uniqueid'] ?? ''), (string)($right['uniqueid'] ?? ''));
		});

		$collapsedJourneys = DetectionEngine::collapseCallJourneys($rows);

		$journeys = [];
		foreach ($collapsedJourneys as $journey) {
			$resolved = $this->resolveInboundJourney($journey, $routes);
			if ($resolved === null) {
				continue;
			}
			$journeys[] = $resolved;
		}

		return [
			'raw_rows' => count($rows),
			'row_cap_reached' => $rowCapReached,
			'collapsed_rows' => count($collapsedJourneys),
			'inbound_journeys' => count($journeys),
			'journeys' => $journeys,
		];
	}

	private function resolveInboundJourney(array $journey, array $routes): ?array {
		$did = trim((string)($journey['did_value'] ?? ''));
		if ($did === '') {
			return null;
		}

		$callerCandidates = array_values(array_unique(array_filter([
			trim((string)($journey['caller_raw'] ?? '')),
			trim((string)($journey['caller_clid'] ?? '')),
		], function ($value) {
			return $value !== '';
		})));

		$fallback = null;
		foreach ($routes as $route) {
			if ((string)$route['did_value'] !== $did) {
				continue;
			}

			$routeCid = trim((string)($route['cid_value'] ?? ''));
			if ($routeCid === '') {
				$fallback = $route;
				continue;
			}

			if (in_array($routeCid, $callerCandidates, true)) {
				return $this->annotateJourney($journey, $route);
			}
		}

		if ($fallback !== null) {
			return $this->annotateJourney($journey, $fallback);
		}

		return null;
	}

	private function annotateJourney(array $journey, array $route): array {
		$journey['route_key'] = (string)$route['route_key'];
		$journey['route_label'] = (string)$route['route_label'];
		$journey['inbound_route_key'] = (string)$route['route_key'];
		$journey['linkedid'] = $journey['identity_type'] === 'linkedid' ? $journey['call_identity'] : null;
		$journey['uniqueid'] = $journey['identity_type'] === 'uniqueid' ? $journey['call_identity'] : null;
		$journey['fingerprint'] = DetectionEngine::conservativeCallFingerprint([
			'calldate' => $journey['completed_at'] ?? '',
			'src' => $journey['caller_raw'] ?? '',
			'dst' => $journey['did_value'] ?? '',
			'dcontext' => $journey['dcontext'] ?? '',
			'clid' => $journey['caller_clid'] ?? '',
			'duration' => '',
			'billsec' => '',
		]);
		$journey['call_started_at'] = (string)$journey['completed_at'];
		$journey['call_completed_at'] = (string)$journey['completed_at'];
		$journey['source_context'] = (string)($journey['dcontext'] ?? '');
		$journey['processed_at'] = $this->now();

		return $journey;
	}

	private function now(): string {
		return (string)call_user_func($this->nowProvider);
	}

	private function loadInboundRoutes(): array {
		$columns = $this->tableColumns('incoming');
		$didColumn = in_array('extension', $columns, true) ? 'extension' : null;
		$cidColumn = in_array('cidnum', $columns, true) ? 'cidnum' : null;
		$descriptionColumn = in_array('description', $columns, true) ? 'description' : null;
		if ($didColumn === null || $cidColumn === null) {
			return [];
		}

		$select = [$didColumn . ' AS did_value', $cidColumn . ' AS cid_value'];
		if ($descriptionColumn !== null) {
			$select[] = $descriptionColumn . ' AS description_value';
		}

		$stmt = $this->pdo->query('SELECT ' . implode(', ', $select) . ' FROM incoming');
		$routes = [];
		foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
			$did = trim((string)($row['did_value'] ?? ''));
			$cid = trim((string)($row['cid_value'] ?? ''));
			if ($did === '' && $cid === '') {
				continue;
			}

			$labelParts = [];
			if ($did !== '') {
				$labelParts[] = $did;
			}
			if ($cid !== '') {
				$labelParts[] = 'CID ' . $cid;
			}
			$description = trim((string)($row['description_value'] ?? ''));
			if ($description !== '') {
				$labelParts[] = $description;
			}

			$routes[] = [
				'route_key' => $did . '|' . $cid,
				'route_label' => $labelParts ? implode(' / ', $labelParts) : ($did . '|' . $cid),
				'did_value' => $did,
				'cid_value' => $cid,
			];
		}

		return $routes;
	}

	private function cdrSelectColumns(array $columns): array {
		$map = [
			'linkedid' => "'' AS linkedid",
			'uniqueid' => "'' AS uniqueid",
			'calldate' => 'calldate',
			'src' => "'' AS src",
			'clid' => "'' AS clid",
			'dst' => "'' AS dst",
			'did' => "'' AS did",
			'dcontext' => "'' AS dcontext",
			'disposition' => "'' AS disposition",
			'channel' => "'' AS channel",
			'dstchannel' => "'' AS dstchannel",
			'duration' => '0 AS duration',
			'billsec' => '0 AS billsec',
		];

		$select = [];
		foreach ($map as $column => $fallback) {
			$select[] = in_array($column, $columns, true) ? $column : $fallback;
		}

		return $select;
	}

	/**
	 * CDR records live in the separate asteriskcdrdb database on real FreePBX
	 * installs, while the PDO connection this class is given is currently
	 * pointed at the asterisk database. SQLite-backed contract tests create
	 * cdr as an unqualified table in the same single-file/in-memory database.
	 */
	private function cdrTable(): string {
		return $this->driverName() === 'sqlite' ? 'cdr' : 'asteriskcdrdb.cdr';
	}

	private function tableExists(string $table): bool {
		try {
			if ($this->driverName() === 'sqlite') {
				$stmt = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
				$stmt->execute([$table]);
				return (int)$stmt->fetchColumn() > 0;
			}

			if (strpos($table, '.') !== false) {
				[$schema, $name] = explode('.', $table, 2);
				$stmt = $this->pdo->prepare('SHOW TABLES FROM ' . $schema . ' LIKE ?');
				$stmt->execute([$name]);
				return (bool)$stmt->fetchColumn();
			}

			$stmt = $this->pdo->prepare('SHOW TABLES LIKE ?');
			$stmt->execute([$table]);
			return (bool)$stmt->fetchColumn();
		} catch (Throwable $e) {
			throw new \RuntimeException('CDR metadata probe failed while checking table existence: ' . $table, 0, $e);
		}
	}

	private function tableColumns(string $table): array {
		try {
			if ($this->driverName() === 'sqlite') {
				$stmt = $this->pdo->query('PRAGMA table_info(' . $table . ')');
				$columns = [];
				foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
					$columns[] = (string)$row['name'];
				}
				return $columns;
			}

			$stmt = $this->pdo->query('DESCRIBE ' . $table);
			$columns = [];
			foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
				$columns[] = (string)$row['Field'];
			}
			return $columns;
		} catch (Throwable $e) {
			throw new \RuntimeException('CDR metadata probe failed while reading table columns: ' . $table, 0, $e);
		}
	}

	private function driverName(): string {
		return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
	}
}