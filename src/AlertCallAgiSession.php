<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

interface AlertCallAgiTransport {
	public function setVariable(string $name, string $value): void;
	public function streamFile(string $file, string $escapeDigits): string;
	public function sayNumber(int $number, string $escapeDigits): string;
	public function sayDigits(string $digits, string $escapeDigits): string;
	public function waitForDigit(int $milliseconds): string;
}

final class AlertCallAgiSession {
	private const ACCEPT_DIGIT = '1';
	private const DECLINE_DIGIT = '2';
	private const ESCAPE_DIGITS = '0123456789*#';
	private const MAX_ATTEMPTS = 3;

	/**
	 * @param callable():bool $isRemotelyAccepted
	 * @return array{response:string,digit:string,accepted:bool}
	 */
	public function run(array $context, AlertCallAgiTransport $transport, callable $isRemotelyAccepted): array {
		$attempt = 1;
		$lastInvalidDigit = '';

		while ($attempt <= self::MAX_ATTEMPTS) {
			$result = $this->checkRemoteAccepted($transport, $isRemotelyAccepted);
			if ($result !== null) {
				return $result;
			}

			$digit = $transport->waitForDigit(1);
			$result = $this->handleDigit($digit, $transport, $isRemotelyAccepted, $lastInvalidDigit);
			if ($result !== null) {
				if ($result['response'] !== 'invalid') {
					return $result;
				}
				$result = $this->retryFromInvalidDigit($attempt, $lastInvalidDigit, $transport, $isRemotelyAccepted);
				if ($result !== null) {
					if ($result['response'] !== 'retry') {
						return $result;
					}
				}
				$attempt++;
				continue;
			}

			$playbackTarget = trim((string)($context['playback_target'] ?? ''));
			if ($playbackTarget !== '') {
				$result = $this->checkRemoteAccepted($transport, $isRemotelyAccepted);
				if ($result !== null) {
					return $result;
				}
				$digit = $transport->streamFile($playbackTarget, self::ESCAPE_DIGITS);
				$result = $this->handleDigit($digit, $transport, $isRemotelyAccepted, $lastInvalidDigit);
				if ($result !== null) {
					if ($result['response'] !== 'invalid') {
						return $result;
					}
					$result = $this->retryFromInvalidDigit($attempt, $lastInvalidDigit, $transport, $isRemotelyAccepted);
					if ($result !== null && $result['response'] !== 'retry') {
						return $result;
					}
					$attempt++;
					continue;
				}
			}

			foreach ($this->summarySegments($context) as $segment) {
				$result = $this->checkRemoteAccepted($transport, $isRemotelyAccepted);
				if ($result !== null) {
					return $result;
				}

				$digit = '';
				switch ($segment['type']) {
					case 'stream':
						$digit = $transport->streamFile((string)$segment['value'], self::ESCAPE_DIGITS);
						break;
					case 'number':
						$digit = $transport->sayNumber((int)$segment['value'], self::ESCAPE_DIGITS);
						break;
					case 'digits':
						$digit = $transport->sayDigits((string)$segment['value'], self::ESCAPE_DIGITS);
						break;
				}

				$result = $this->handleDigit($digit, $transport, $isRemotelyAccepted, $lastInvalidDigit);
				if ($result !== null) {
					if ($result['response'] !== 'invalid') {
						return $result;
					}
					$result = $this->retryFromInvalidDigit($attempt, $lastInvalidDigit, $transport, $isRemotelyAccepted);
					if ($result !== null && $result['response'] !== 'retry') {
						return $result;
					}
					$attempt++;
					continue 2;
				}
			}

			$result = $this->checkRemoteAccepted($transport, $isRemotelyAccepted);
			if ($result !== null) {
				return $result;
			}

			$digit = $transport->waitForDigit(10000);
			$result = $this->handleDigit($digit, $transport, $isRemotelyAccepted, $lastInvalidDigit);
			if ($result !== null) {
				if ($result['response'] !== 'invalid') {
					return $result;
				}
				$result = $this->retryFromInvalidDigit($attempt, $lastInvalidDigit, $transport, $isRemotelyAccepted);
				if ($result !== null && $result['response'] !== 'retry') {
					return $result;
				}
				$attempt++;
				continue;
			}

			if ($attempt >= self::MAX_ATTEMPTS) {
				return $this->terminalNoResponse($transport, $lastInvalidDigit);
			}

			$attempt++;
		}

		return $this->terminalNoResponse($transport, $lastInvalidDigit);
	}

	/**
	 * @return array<int,array{type:string,value:string|int}>
	 */
	private function summarySegments(array $context): array {
		$mode = strtolower(trim((string)($context['summary_mode'] ?? 'repeat')));
		$callCount = max(0, (int)($context['summary_call_count'] ?? 0));
		$threshold = max(0, (int)($context['summary_threshold'] ?? 0));
		$windowMinutes = max(0, (int)($context['summary_window_minutes'] ?? 0));
		$callerKind = strtolower(trim((string)($context['summary_caller_kind'] ?? 'none')));
		$callerValue = trim((string)($context['summary_caller_value'] ?? ''));
		$didValue = trim((string)($context['summary_did_value'] ?? ''));

		$segments = [
			['type' => 'stream', 'value' => 'beep&beep&beep&warning&beep&beep&beep'],
			['type' => 'stream', 'value' => 'this&alert&has-been&initiated&for'],
		];

		if ($mode === 'invert') {
			$segments[] = ['type' => 'stream', 'value' => 'less-than'];
			$segments[] = ['type' => 'number', 'value' => $threshold];
			$segments[] = ['type' => 'stream', 'value' => $threshold === 1 ? 'call' : 'calls'];
		} else {
			$segments[] = ['type' => 'number', 'value' => $callCount];
			$segments[] = ['type' => 'stream', 'value' => $callCount === 1 ? 'call' : 'calls'];
		}

		$segments[] = ['type' => 'stream', 'value' => 'within'];
		$segments[] = ['type' => 'number', 'value' => $windowMinutes];
		$segments[] = ['type' => 'stream', 'value' => $windowMinutes === 1 ? 'minute' : 'minutes'];

		if ($callerKind === 'unknown') {
			$segments[] = ['type' => 'stream', 'value' => 'from-unknown-caller'];
		} elseif ($callerKind !== 'none' && $callerValue !== '') {
			$segments[] = ['type' => 'stream', 'value' => 'from'];
			$segments[] = ['type' => 'digits', 'value' => $callerValue];
		}

		if ($didValue !== '') {
			$segments[] = ['type' => 'stream', 'value' => 'calling&number'];
			$segments[] = ['type' => 'digits', 'value' => $didValue];
		}

		$segments[] = ['type' => 'stream', 'value' => 'vqplus-accept'];

		return $segments;
	}

	private function handleDigit(string $digit, AlertCallAgiTransport $transport, callable $isRemotelyAccepted, string &$lastInvalidDigit): ?array {
		$digit = trim($digit);
		if ($digit === '') {
			return null;
		}

		if ($digit === self::ACCEPT_DIGIT) {
			return $this->terminalAccepted($transport);
		}

		if ($digit === self::DECLINE_DIGIT) {
			return $this->terminalDeclined($transport);
		}

		$lastInvalidDigit = substr($digit, 0, 8);
		return ['response' => 'invalid', 'digit' => $lastInvalidDigit, 'accepted' => false];
	}

	private function retryFromInvalidDigit(int $attempt, string $lastInvalidDigit, AlertCallAgiTransport $transport, callable $isRemotelyAccepted): ?array {
		if ($attempt >= self::MAX_ATTEMPTS) {
			return $this->terminalNoResponse($transport, $lastInvalidDigit);
		}

		$result = $this->checkRemoteAccepted($transport, $isRemotelyAccepted);
		if ($result !== null) {
			return $result;
		}

		$digit = $transport->streamFile('sorry&please-try-again', self::ESCAPE_DIGITS);
		$result = $this->handleDigit($digit, $transport, $isRemotelyAccepted, $lastInvalidDigit);
		if ($result !== null && $result['response'] !== 'invalid') {
			return $result;
		}

		return ['response' => 'retry', 'digit' => $lastInvalidDigit, 'accepted' => false];
	}

	private function checkRemoteAccepted(AlertCallAgiTransport $transport, callable $isRemotelyAccepted): ?array {
		if (!call_user_func($isRemotelyAccepted)) {
			return null;
		}

		return $this->terminalRemoteAccepted($transport);
	}

	private function terminalAccepted(AlertCallAgiTransport $transport): array {
		$transport->setVariable('REPEATCALLER_ALERT_COMPLETED', '1');
		$transport->streamFile('auth-thankyou', '');
		$transport->streamFile('goodbye', '');

		return ['response' => 'accepted', 'digit' => self::ACCEPT_DIGIT, 'accepted' => true];
	}

	private function terminalDeclined(AlertCallAgiTransport $transport): array {
		$transport->setVariable('REPEATCALLER_ALERT_COMPLETED', '1');
		$transport->streamFile('auth-thankyou', '');
		$transport->streamFile('goodbye', '');

		return ['response' => 'declined', 'digit' => self::DECLINE_DIGIT, 'accepted' => false];
	}

	private function terminalNoResponse(AlertCallAgiTransport $transport, string $lastInvalidDigit): array {
		$transport->setVariable('REPEATCALLER_ALERT_COMPLETED', '1');
		$transport->streamFile('auth-thankyou', '');
		$transport->streamFile('goodbye', '');

		return ['response' => 'answered_no_response', 'digit' => $lastInvalidDigit, 'accepted' => false];
	}

	private function terminalRemoteAccepted(AlertCallAgiTransport $transport): array {
		$transport->setVariable('REPEATCALLER_ALERT_COMPLETED', '1');
		$transport->streamFile('auth-thankyou', '');
		$transport->streamFile('goodbye', '');

		return ['response' => 'remote_accepted', 'digit' => '', 'accepted' => false];
	}
}