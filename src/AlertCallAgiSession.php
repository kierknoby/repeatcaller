<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

require_once __DIR__ . '/AlertCallPromptResolver.php';

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

	private AlertCallPromptResolver $promptResolver;
	/** @var array{invalid:string,retry:string,thankyou:string,goodbye:string,remote_accepted:string} */
	private array $interactionPrompts;

	public function __construct(?AlertCallPromptResolver $promptResolver = null) {
		$this->promptResolver = $promptResolver ?? new AlertCallPromptResolver();
	}

	/**
	 * @param callable():bool $isRemotelyAccepted
	 * @return array{response:string,digit:string,accepted:bool}
	 */
	public function run(array $context, AlertCallAgiTransport $transport, callable $isRemotelyAccepted): array {
		$attempt = 1;
		$lastInvalidDigit = '';
		$playbackLanguage = trim((string)($context['playback_language'] ?? ''));
		$recordingLanguage = trim((string)($context['recording_language'] ?? ''));
		$availablePrompts = array_key_exists('available_prompts', $context) && is_array($context['available_prompts'])
			? array_values($context['available_prompts'])
			: null;
		$fallbackPrompts = array_key_exists('fallback_prompts', $context) && is_array($context['fallback_prompts'])
			? array_values($context['fallback_prompts'])
			: null;
		$promptResolution = $this->promptResolver->resolve($playbackLanguage, $availablePrompts, $context, $fallbackPrompts);
		if ($promptResolution['profile'] === '') {
			$transport->setVariable('REPEATCALLER_ALERT_COMPLETED', '1');
			return ['response' => 'unavailable', 'digit' => '', 'accepted' => false];
		}
		$this->interactionPrompts = $this->promptResolver->interactionPrompts($promptResolution['profile']);
		$generatedLanguage = $promptResolution['generated_language'];
		if ($generatedLanguage !== '') {
			$transport->setVariable('CHANNEL(language)', $generatedLanguage);
		}
		$remotePromptsConfigured = false;

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
			if (!$remotePromptsConfigured) {
				$this->configureRemotePrompts($transport);
				$remotePromptsConfigured = true;
			}

			$playbackTarget = trim((string)($context['playback_target'] ?? ''));
			if ($playbackTarget !== '') {
				$result = $this->checkRemoteAccepted($transport, $isRemotelyAccepted);
				if ($result !== null) {
					return $result;
				}
				if ($recordingLanguage !== '') {
					$transport->setVariable('CHANNEL(language)', $recordingLanguage);
				}
				$digit = $transport->streamFile($playbackTarget, self::ESCAPE_DIGITS);
				if ($recordingLanguage !== '') {
					$transport->setVariable('CHANNEL(language)', $generatedLanguage !== '' ? $generatedLanguage : $playbackLanguage);
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
					continue;
				}
			}

			foreach ($this->promptResolver->summarySegments($context, $promptResolution['profile']) as $segment) {
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

		$digit = $this->streamInteractionPrompt('invalid', self::ESCAPE_DIGITS, $transport);
		$result = $this->handleDigit($digit, $transport, $isRemotelyAccepted, $lastInvalidDigit);
		if ($result !== null && $result['response'] !== 'invalid') {
			return $result;
		}

		$result = $this->checkRemoteAccepted($transport, $isRemotelyAccepted);
		if ($result !== null) {
			return $result;
		}

		$digit = $this->streamInteractionPrompt('retry', self::ESCAPE_DIGITS, $transport);
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
		$this->streamInteractionPrompt('thankyou', '', $transport);
		$this->streamInteractionPrompt('goodbye', '', $transport);

		return ['response' => 'accepted', 'digit' => self::ACCEPT_DIGIT, 'accepted' => true];
	}

	private function terminalDeclined(AlertCallAgiTransport $transport): array {
		$transport->setVariable('REPEATCALLER_ALERT_COMPLETED', '1');
		$this->streamInteractionPrompt('thankyou', '', $transport);
		$this->streamInteractionPrompt('goodbye', '', $transport);

		return ['response' => 'declined', 'digit' => self::DECLINE_DIGIT, 'accepted' => false];
	}

	private function terminalNoResponse(AlertCallAgiTransport $transport, string $lastInvalidDigit): array {
		$transport->setVariable('REPEATCALLER_ALERT_COMPLETED', '1');
		$this->streamInteractionPrompt('thankyou', '', $transport);
		$this->streamInteractionPrompt('goodbye', '', $transport);

		return ['response' => 'answered_no_response', 'digit' => $lastInvalidDigit, 'accepted' => false];
	}

	private function terminalRemoteAccepted(AlertCallAgiTransport $transport): array {
		$transport->setVariable('REPEATCALLER_ALERT_COMPLETED', '1');
		$this->streamInteractionPrompt('remote_accepted', '', $transport);
		$this->streamInteractionPrompt('thankyou', '', $transport);
		$this->streamInteractionPrompt('goodbye', '', $transport);

		return ['response' => 'remote_accepted', 'digit' => '', 'accepted' => false];
	}

	private function streamInteractionPrompt(string $name, string $escapeDigits, AlertCallAgiTransport $transport): string {
		$prompt = $this->interactionPrompts[$name] ?? '';
		return $prompt === '' ? '' : $transport->streamFile($prompt, $escapeDigits);
	}

	private function configureRemotePrompts(AlertCallAgiTransport $transport): void {
		$englishPrompts = $this->promptResolver->interactionPrompts('english');
		$variables = [
			'remote_accepted' => 'REPEATCALLER_ALERT_REMOTE_PROMPT',
			'thankyou' => 'REPEATCALLER_ALERT_THANKYOU_PROMPT',
			'goodbye' => 'REPEATCALLER_ALERT_GOODBYE_PROMPT',
		];
		foreach ($variables as $promptName => $variableName) {
			if ($this->interactionPrompts[$promptName] !== $englishPrompts[$promptName]) {
				$transport->setVariable($variableName, $this->interactionPrompts[$promptName]);
			}
		}
	}
}