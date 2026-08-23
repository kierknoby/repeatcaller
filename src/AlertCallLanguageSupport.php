<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

require_once __DIR__ . '/AlertCallPromptInventory.php';
require_once __DIR__ . '/AlertCallPromptResolver.php';

final class AlertCallLanguageSupport {
	private string $soundsRoot;
	private AlertCallPromptResolver $resolver;

	public function __construct(string $soundsRoot, ?AlertCallPromptResolver $resolver = null) {
		$this->soundsRoot = rtrim($soundsRoot, '/');
		$this->resolver = $resolver ?? new AlertCallPromptResolver();
	}

	/**
	 * @return array{fallback_available:bool,fallback_language:string,supported_profiles:array<int,array{language:string,detected_language:string,profile:string,complete:bool,supported:bool,native:bool,adapted_wording:bool,fallback_only:bool,missing_required_prompts:array<int,string>,fallback_target:string}>,installed_languages:array<int,string>,languages:array<int,array{language:string,detected_language:string,profile:string,complete:bool,supported:bool,native:bool,adapted_wording:bool,fallback_only:bool,missing_required_prompts:array<int,string>,fallback_target:string}>}
	 */
	public function status(): array {
		$supportedProfiles = $this->resolver->supportedProfiles();
		$english = $this->inspectProfile($supportedProfiles['en']);
		$french = $this->inspectProfile($supportedProfiles['fr']);
		$fallbackLanguage = $english['complete'] ? $english['detected_language'] : '';
		$english['fallback_target'] = $fallbackLanguage;
		$french['fallback_target'] = $fallbackLanguage;
		$supportedProfiles = [$english, $french];

		return [
			'fallback_available' => $english['complete'],
			'fallback_language' => $fallbackLanguage,
			'supported_profiles' => $supportedProfiles,
			'installed_languages' => AlertCallPromptInventory::installedLanguages($this->soundsRoot),
			'languages' => $supportedProfiles,
		];
	}

	/**
	 * @return array{available:bool,profile:string,language:string,missing_required_prompts:array<int,string>,fallback_language:string}
	 */
	public function resolve(string $language): array {
		$family = strtolower(explode('_', str_replace('-', '_', trim($language)), 2)[0]);
		$status = $this->status();
		$english = $status['languages'][0];
		$supportedProfiles = $this->resolver->supportedProfiles();
		if ($family === '') {
			$family = 'en';
		}
		if (isset($supportedProfiles[$family])) {
			$installedLanguage = $this->exactInstalledLanguage($language !== '' ? $language : $family);
			$profile = $supportedProfiles[$family]['profile'];
			$inventory = $installedLanguage !== '' ? AlertCallPromptInventory::discover($this->soundsRoot, $installedLanguage) : null;
			if ($this->resolver->missingPrompts($profile, $inventory) === []) {
				return ['available' => true, 'profile' => $profile, 'language' => $installedLanguage, 'missing_required_prompts' => [], 'fallback_language' => ''];
			}
		}
		if ($english['complete']) {
			return ['available' => true, 'profile' => 'english', 'language' => $english['detected_language'], 'missing_required_prompts' => [], 'fallback_language' => $english['detected_language']];
		}

		return ['available' => false, 'profile' => '', 'language' => '', 'missing_required_prompts' => $english['missing_required_prompts'], 'fallback_language' => ''];
	}

	/**
	 * Reports formats present on required Alert Call prompts for this locale.
	 *
	 * @return array<int,string>
	 */
	public function availableCodecs(string $language): array {
		$family = strtolower(explode('_', str_replace('-', '_', trim($language)), 2)[0]);
		$profiles = $this->resolver->supportedProfiles();
		$profile = (string)($profiles[$family]['profile'] ?? 'english');
		return AlertCallPromptInventory::availableCodecs(
			$this->soundsRoot,
			$language,
			$this->resolver->requiredPrompts($profile)
		);
	}

	private function exactInstalledLanguage(string $language): string {
		$normalised = strtolower(str_replace('-', '_', trim($language)));
		foreach (AlertCallPromptInventory::installedLanguages($this->soundsRoot) as $installedLanguage) {
			if (strtolower(str_replace('-', '_', $installedLanguage)) === $normalised) {
				return $installedLanguage;
			}
		}
		return '';
	}

	/**
	 * @param array<int,string> $candidates
	 * @return array{language:string,inventory:array<int,string>|null,missing:array<int,string>}
	 */
	private function bestInventory(array $candidates, string $profile): array {
		$best = ['language' => '', 'inventory' => null, 'missing' => $this->resolver->requiredPrompts($profile)];
		foreach ($candidates as $candidate) {
			$inventory = AlertCallPromptInventory::discover($this->soundsRoot, $candidate);
			$missing = $this->resolver->missingPrompts($profile, $inventory);
			if ($inventory !== null && ($best['inventory'] === null || count($missing) < count($best['missing']))) {
				$best = ['language' => $candidate, 'inventory' => $inventory, 'missing' => $missing];
			}
			if ($missing === []) {
				return ['language' => $candidate, 'inventory' => $inventory, 'missing' => []];
			}
		}
		return $best;
	}

	/**
	 * @param array{language:string,candidates:array<int,string>,profile:string,adapted_wording:bool} $definition
	 * @return array{language:string,locale_candidates:array<int,string>,detected_language:string,profile:string,complete:bool,supported:bool,native:bool,adapted_wording:bool,fallback_only:bool,missing_required_prompts:array<int,string>,fallback_target:string}
	 */
	private function inspectProfile(array $definition): array {
		$inventory = $this->bestInventory($definition['candidates'], $definition['profile']);
		return [
			'language' => $definition['language'],
			'locale_candidates' => $definition['candidates'],
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
