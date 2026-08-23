<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

final class AlertCallPromptResolver {
	private const FALLBACK_LANGUAGE = 'en';
	private const SUPPORTED_PROFILES = [
		'en' => [
			'language' => 'English',
			'candidates' => ['en', 'en_GB', 'en_AU', 'en_NZ'],
			'profile' => 'english',
			'adapted_wording' => false,
		],
		'fr' => [
			'language' => 'French',
			'candidates' => ['fr_FR', 'fr'],
			'profile' => 'french',
			'adapted_wording' => true,
		],
	];

	private const PROFILE_PROMPTS = [
		'english' => [
			'invalid' => 'sorry',
			'retry' => 'please-try-again',
			'thankyou' => 'auth-thankyou',
			'goodbye' => 'goodbye',
			'remote_accepted' => 'incoming-call-no-longer-avail',
		],
		'french' => [
			'invalid' => 'sorry',
			'retry' => 'please-try-again',
			'thankyou' => 'auth-thankyou',
			'goodbye' => 'goodbye',
			'remote_accepted' => 'incoming-call-no-longer-avail',
		],
	];

	/**
	 * Maintainer-approved production profiles. Installed prompt inventory only
	 * determines whether one of these supported profiles is complete and safe.
	 *
	 * @return array<string,array{language:string,candidates:array<int,string>,profile:string,adapted_wording:bool}>
	 */
	public function supportedProfiles(): array {
		return self::SUPPORTED_PROFILES;
	}

	/**
	 * @param array<int,string>|null $availablePrompts
	 * @return array{profile:string,generated_language:string}
	 */
	public function resolve(string $language, ?array $availablePrompts = null, array $context = [], ?array $fallbackPrompts = null): array {
		$languageFamily = $this->languageFamily($language);
		if ($languageFamily === '') {
			$languageFamily = 'en';
		}
		$supportedProfile = self::SUPPORTED_PROFILES[$languageFamily] ?? null;
		if (($supportedProfile['profile'] ?? '') === 'english' && $this->hasRequiredPrompts($this->requiredPrompts('english'), $availablePrompts)) {
			return ['profile' => 'english', 'generated_language' => ''];
		}

		if (($supportedProfile['profile'] ?? '') === 'french' && $this->hasRequiredPrompts($this->requiredPrompts('french'), $availablePrompts)) {
			return ['profile' => 'french', 'generated_language' => ''];
		}

		if ($this->hasRequiredPrompts($this->requiredPrompts('english'), $fallbackPrompts)) {
			$fallbackLanguage = trim((string)($context['fallback_language'] ?? self::FALLBACK_LANGUAGE));
			return ['profile' => 'english', 'generated_language' => $fallbackLanguage !== '' ? $fallbackLanguage : self::FALLBACK_LANGUAGE];
		}

		return ['profile' => '', 'generated_language' => ''];
	}

	/**
	 * @return array<int,string>
	 */
	public function requiredPrompts(string $profile): array {
		$interaction = array_values(self::PROFILE_PROMPTS[$profile] ?? []);
		if ($profile === 'french') {
			return array_values(array_unique(array_merge($interaction, [
				'beep', 'warning', 'conf-thereare', 'queue-less-than', 'minutes',
				'telephone-number', 'followme/call-from', 'from-unknown-caller', 'vqplus-accept',
			])));
		}

		return array_values(array_unique(array_merge($interaction, [
			'beep', 'warning', 'this', 'alert', 'has-been', 'initiated', 'for',
			'less-than', 'call', 'calls', 'within', 'minute', 'minutes', 'from',
			'from-unknown-caller', 'calling', 'number', 'vqplus-accept',
		])));
	}

	/**
	 * @param array<int,string>|null $availablePrompts
	 * @return array<int,string>
	 */
	public function missingPrompts(string $profile, ?array $availablePrompts): array {
		if ($availablePrompts === null) {
			return $this->requiredPrompts($profile);
		}
		$available = array_fill_keys($availablePrompts, true);
		return array_values(array_filter($this->requiredPrompts($profile), static function (string $prompt) use ($available): bool {
			return !isset($available[$prompt]);
		}));
	}

	/**
	 * @return array{invalid:string,retry:string,thankyou:string,goodbye:string,remote_accepted:string}
	 */
	public function interactionPrompts(string $profile): array {
		return self::PROFILE_PROMPTS[$profile] ?? self::PROFILE_PROMPTS['english'];
	}

	/**
	 * @return array<int,array{type:string,value:string|int}>
	 */
	public function summarySegments(array $context, string $profile): array {
		if ($profile === 'french') {
			return $this->frenchSummarySegments($context);
		}
		return $this->englishSummarySegments($context);
	}

	/**
	 * @return array<int,array{type:string,value:string|int}>
	 */
	private function englishSummarySegments(array $context): array {
		$values = $this->summaryValues($context);
		$segments = $this->warningSegments();
		$segments[] = ['type' => 'stream', 'value' => 'this'];
		$segments[] = ['type' => 'stream', 'value' => 'alert'];
		$segments[] = ['type' => 'stream', 'value' => 'has-been'];
		$segments[] = ['type' => 'stream', 'value' => 'initiated'];
		$segments[] = ['type' => 'stream', 'value' => 'for'];
		$this->appendCountSegments($segments, $values);
		$this->appendWindowSegments($segments, $values);
		$this->appendCallerSegments($segments, $values);

		if ($values['did_value'] !== '') {
			$segments[] = ['type' => 'stream', 'value' => 'calling'];
			$segments[] = ['type' => 'stream', 'value' => 'number'];
			$segments[] = ['type' => 'digits', 'value' => $values['did_value']];
		}

		$segments[] = ['type' => 'stream', 'value' => 'vqplus-accept'];
		return $segments;
	}

	/**
	 * @return array<int,array{type:string,value:string|int}>
	 */
	private function frenchSummarySegments(array $context): array {
		$values = $this->summaryValues($context);
		$segments = $this->warningSegments();
		$segments[] = [
			'type' => 'stream',
			'value' => $values['mode'] === 'invert' ? 'queue-less-than' : 'conf-thereare',
		];
		$segments[] = [
			'type' => 'number',
			'value' => $values['mode'] === 'invert' ? $values['threshold'] : $values['call_count'],
		];

		if ($values['did_value'] !== '') {
			$segments[] = ['type' => 'stream', 'value' => 'telephone-number'];
			$segments[] = ['type' => 'digits', 'value' => $values['did_value']];
		}

		$segments[] = ['type' => 'number', 'value' => $values['window_minutes']];
		$segments[] = ['type' => 'stream', 'value' => 'minutes'];

		if ($values['caller_kind'] === 'unknown') {
			$segments[] = ['type' => 'stream', 'value' => 'from-unknown-caller'];
		} elseif ($values['caller_kind'] !== 'none' && $values['caller_value'] !== '') {
			$segments[] = ['type' => 'stream', 'value' => 'followme/call-from'];
			$segments[] = ['type' => 'digits', 'value' => $values['caller_value']];
		}

		$segments[] = ['type' => 'stream', 'value' => 'vqplus-accept'];
		return $segments;
	}

	/**
	 * @return array{mode:string,call_count:int,threshold:int,window_minutes:int,caller_kind:string,caller_value:string,did_value:string}
	 */
	private function summaryValues(array $context): array {
		return [
			'mode' => strtolower(trim((string)($context['summary_mode'] ?? 'repeat'))),
			'call_count' => max(0, (int)($context['summary_call_count'] ?? 0)),
			'threshold' => max(0, (int)($context['summary_threshold'] ?? 0)),
			'window_minutes' => max(0, (int)($context['summary_window_minutes'] ?? 0)),
			'caller_kind' => strtolower(trim((string)($context['summary_caller_kind'] ?? 'none'))),
			'caller_value' => trim((string)($context['summary_caller_value'] ?? '')),
			'did_value' => trim((string)($context['summary_did_value'] ?? '')),
		];
	}

	/**
	 * @return array<int,array{type:string,value:string|int}>
	 */
	private function warningSegments(): array {
		return [
			['type' => 'stream', 'value' => 'beep'],
			['type' => 'stream', 'value' => 'beep'],
			['type' => 'stream', 'value' => 'beep'],
			['type' => 'stream', 'value' => 'warning'],
			['type' => 'stream', 'value' => 'beep'],
			['type' => 'stream', 'value' => 'beep'],
			['type' => 'stream', 'value' => 'beep'],
		];
	}

	/**
	 * @param array<int,array{type:string,value:string|int}> $segments
	 * @param array{mode:string,call_count:int,threshold:int,window_minutes:int,caller_kind:string,caller_value:string,did_value:string} $values
	 */
	private function appendCountSegments(array &$segments, array $values): void {
		$count = $values['call_count'];
		if ($values['mode'] === 'invert') {
			$segments[] = ['type' => 'stream', 'value' => 'less-than'];
			$count = $values['threshold'];
		}
		$segments[] = ['type' => 'number', 'value' => $count];
		$segments[] = ['type' => 'stream', 'value' => $count === 1 ? 'call' : 'calls'];
	}

	/**
	 * @param array<int,array{type:string,value:string|int}> $segments
	 * @param array{mode:string,call_count:int,threshold:int,window_minutes:int,caller_kind:string,caller_value:string,did_value:string} $values
	 */
	private function appendWindowSegments(array &$segments, array $values): void {
		$segments[] = ['type' => 'stream', 'value' => 'within'];
		$segments[] = ['type' => 'number', 'value' => $values['window_minutes']];
		$segments[] = ['type' => 'stream', 'value' => $values['window_minutes'] === 1 ? 'minute' : 'minutes'];
	}

	/**
	 * @param array<int,array{type:string,value:string|int}> $segments
	 * @param array{mode:string,call_count:int,threshold:int,window_minutes:int,caller_kind:string,caller_value:string,did_value:string} $values
	 */
	private function appendCallerSegments(array &$segments, array $values): void {
		if ($values['caller_kind'] === 'unknown') {
			$segments[] = ['type' => 'stream', 'value' => 'from-unknown-caller'];
		} elseif ($values['caller_kind'] !== 'none' && $values['caller_value'] !== '') {
			$segments[] = ['type' => 'stream', 'value' => 'from'];
			$segments[] = ['type' => 'digits', 'value' => $values['caller_value']];
		}
	}

	/**
	 * @param array<int,string> $requiredPrompts
	 * @param array<int,string>|null $availablePrompts
	 */
	private function hasRequiredPrompts(array $requiredPrompts, ?array $availablePrompts): bool {
		if ($availablePrompts === null) {
			return false;
		}

		$available = array_fill_keys($availablePrompts, true);
		foreach ($requiredPrompts as $prompt) {
			if (!isset($available[$prompt])) {
				return false;
			}
		}
		return true;
	}

	private function languageFamily(string $language): string {
		$language = strtolower(str_replace('-', '_', trim($language)));
		return explode('_', $language, 2)[0];
	}
}
