<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

require_once __DIR__ . '/AlertCallPromptInventory.php';
require_once __DIR__ . '/AlertCallPromptResolver.php';

final class AlertCallLanguageSupport {
	private const FALLBACK_ONLY_LANGUAGES = [
		['language' => 'Spanish', 'candidates' => ['es_ES', 'es']],
		['language' => 'German', 'candidates' => ['de_DE', 'de']],
	];

	private string $soundsRoot;
	private AlertCallPromptResolver $resolver;

	public function __construct(string $soundsRoot, ?AlertCallPromptResolver $resolver = null) {
		$this->soundsRoot = rtrim($soundsRoot, '/');
		$this->resolver = $resolver ?? new AlertCallPromptResolver();
	}

	/**
	 * @return array{fallback_available:bool,fallback_language:string,languages:array<int,array{language:string,detected_language:string,profile:string,complete:bool,supported:bool,native:bool,adapted_wording:bool,fallback_only:bool,missing_required_prompts:array<int,string>,fallback_target:string}>}
	 */
	public function status(): array {
		$supportedProfiles = $this->resolver->supportedProfiles();
		$english = $this->inspectProfile($supportedProfiles['en']);
		$french = $this->inspectProfile($supportedProfiles['fr']);
		$fallbackLanguage = $english['complete'] ? $english['detected_language'] : '';
		if (!$french['complete']) {
			$french['fallback_target'] = $fallbackLanguage;
		}
		$languages = [$english, $french];
		foreach (self::FALLBACK_ONLY_LANGUAGES as $definition) {
			$languages[] = [
				'language' => $definition['language'],
				'detected_language' => $this->detectLanguage($definition['candidates']),
				'profile' => 'none',
				'complete' => false,
				'supported' => false,
				'native' => false,
				'adapted_wording' => false,
				'fallback_only' => true,
				'missing_required_prompts' => [],
				'fallback_target' => $fallbackLanguage,
			];
		}

		return [
			'fallback_available' => $english['complete'],
			'fallback_language' => $fallbackLanguage,
			'languages' => $languages,
		];
	}

	/**
	 * @return array{available:bool,profile:string,language:string,missing_required_prompts:array<int,string>,fallback_language:string}
	 */
	public function resolve(string $language): array {
		$family = strtolower(explode('_', str_replace('-', '_', trim($language)), 2)[0]);
		$status = $this->status();
		$english = $status['languages'][0];
		if ($family === 'fr') {
			$french = $status['languages'][1];
			if ($french['complete']) {
				return ['available' => true, 'profile' => 'french', 'language' => $french['detected_language'], 'missing_required_prompts' => [], 'fallback_language' => ''];
			}
		}
		if (($family === '' || $family === 'en') && $english['complete']) {
			return ['available' => true, 'profile' => 'english', 'language' => $english['detected_language'], 'missing_required_prompts' => [], 'fallback_language' => ''];
		}
		if ($english['complete']) {
			return ['available' => true, 'profile' => 'english', 'language' => $english['detected_language'], 'missing_required_prompts' => [], 'fallback_language' => $english['detected_language']];
		}

		return ['available' => false, 'profile' => '', 'language' => '', 'missing_required_prompts' => $english['missing_required_prompts'], 'fallback_language' => ''];
	}

	/**
	 * @param array<int,string> $candidates
	 * @return array{language:string,inventory:array<int,string>|null,missing:array<int,string>}
	 */
	private function bestInventory(array $candidates, string $profile): array {
		$best = ['language' => (string)end($candidates), 'inventory' => null, 'missing' => $this->resolver->requiredPrompts($profile)];
		foreach ($candidates as $candidate) {
			$inventory = AlertCallPromptInventory::discover($this->soundsRoot, $candidate);
			$missing = $this->resolver->missingPrompts($profile, $inventory);
			if ($inventory !== null && count($missing) < count($best['missing'])) {
				$best = ['language' => $candidate, 'inventory' => $inventory, 'missing' => $missing];
			}
			if ($missing === []) {
				return ['language' => $candidate, 'inventory' => $inventory, 'missing' => []];
			}
		}
		return $best;
	}

	/**
	 * @param array<int,string> $candidates
	 */
	private function detectLanguage(array $candidates): string {
		foreach ($candidates as $candidate) {
			if (AlertCallPromptInventory::discover($this->soundsRoot, $candidate) !== null) {
				return $candidate;
			}
		}
		return (string)end($candidates);
	}

	/**
	 * @param array{language:string,candidates:array<int,string>,profile:string,adapted_wording:bool} $definition
	 * @return array{language:string,detected_language:string,profile:string,complete:bool,supported:bool,native:bool,adapted_wording:bool,fallback_only:bool,missing_required_prompts:array<int,string>,fallback_target:string}
	 */
	private function inspectProfile(array $definition): array {
		$inventory = $this->bestInventory($definition['candidates'], $definition['profile']);
		return [
			'language' => $definition['language'],
			'detected_language' => $inventory['language'],
			'profile' => $definition['profile'],
			'complete' => $inventory['missing'] === [],
			'supported' => true,
			'native' => true,
			'adapted_wording' => $definition['adapted_wording'],
			'fallback_only' => false,
			'missing_required_prompts' => $inventory['missing'],
			'fallback_target' => '',
		];
	}
}
