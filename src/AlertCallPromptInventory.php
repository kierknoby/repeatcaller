<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

final class AlertCallPromptInventory {
	private const AUDIO_EXTENSIONS = ['alaw', 'g722', 'g729', 'gsm', 'sln', 'sln16', 'ulaw', 'wav', 'wav49'];
	private const NON_LANGUAGE_DIRECTORIES = ['custom', 'tmp'];

	/**
	 * @return array<int,string>|null
	 */
	public static function discover(string $soundsRoot, string $language): ?array {
		$language = trim($language);
		if ($language === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $language) !== 1 || !is_dir($soundsRoot)) {
			return null;
		}

		$normalisedLanguage = str_replace('-', '_', $language);
		$soundsRoot = rtrim($soundsRoot, '/');
		$availableDirectories = [];
		try {
			$entries = new \FilesystemIterator($soundsRoot, \FilesystemIterator::SKIP_DOTS);
			foreach ($entries as $entry) {
				if (!$entry->isDir()) {
					continue;
				}
				$key = strtolower($entry->getFilename());
				$availableDirectories[$key] = array_key_exists($key, $availableDirectories)
					? null
					: $entry->getPathname();
			}
		} catch (\UnexpectedValueException $e) {
			return null;
		}
		$directory = $soundsRoot . '/' . $normalisedLanguage;
		if (!is_dir($directory)) {
			$directory = $availableDirectories[strtolower($normalisedLanguage)] ?? null;
			if ($directory === null) {
				return null;
			}
		}
		$prompts = [];
		try {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
		} catch (\UnexpectedValueException $e) {
			return null;
		}
		foreach ($files as $file) {
			if (!$file->isFile()) {
				continue;
			}
			$extension = strtolower((string)$file->getExtension());
			if (!in_array($extension, self::AUDIO_EXTENSIONS, true)) {
				continue;
			}
			$relativePath = substr($file->getPathname(), strlen($directory) + 1);
			$relativePrompt = substr($relativePath, 0, -(strlen($extension) + 1));
			$prompts[str_replace(DIRECTORY_SEPARATOR, '/', $relativePrompt)] = true;
		}

		return array_keys($prompts);
	}

	/**
	 * @return array<int,string>
	 */
	public static function installedLanguages(string $soundsRoot): array {
		if (!is_dir($soundsRoot)) {
			return [];
		}
		$languages = [];
		try {
			$entries = new \FilesystemIterator($soundsRoot, \FilesystemIterator::SKIP_DOTS);
			foreach ($entries as $entry) {
				$name = $entry->getFilename();
				if (
					$entry->isDir()
					&& !in_array(strtolower($name), self::NON_LANGUAGE_DIRECTORIES, true)
					&& preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z]{2,8})?$/', $name) === 1
				) {
					$languages[] = $name;
				}
			}
		} catch (\UnexpectedValueException $e) {
			return [];
		}
		natcasesort($languages);
		return array_values($languages);
	}

	/**
	 * @return array<int,string>
	 */
	public static function installedCodecs(string $soundsRoot, string $language): array {
		$language = trim($language);
		if ($language === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $language) !== 1 || !is_dir($soundsRoot)) {
			return [];
		}

		$normalisedLanguage = str_replace('-', '_', $language);
		$soundsRoot = rtrim($soundsRoot, '/');
		$directory = $soundsRoot . '/' . $normalisedLanguage;
		if (!is_dir($directory)) {
			try {
				$entries = new \FilesystemIterator($soundsRoot, \FilesystemIterator::SKIP_DOTS);
				$directory = '';
				foreach ($entries as $entry) {
					if ($entry->isDir() && strcasecmp($entry->getFilename(), $normalisedLanguage) === 0) {
						$directory = $entry->getPathname();
						break;
					}
				}
			} catch (\UnexpectedValueException $e) {
				return [];
			}
			if ($directory === '') {
				return [];
			}
		}

		$available = [];
		try {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ($files as $file) {
				if (!$file->isFile()) {
					continue;
				}
				$extension = strtolower((string)$file->getExtension());
				if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
					$available[$extension] = true;
				}
			}
		} catch (\UnexpectedValueException $e) {
			return [];
		}

		return array_values(array_filter(self::AUDIO_EXTENSIONS, function (string $extension) use ($available): bool {
			return isset($available[$extension]);
		}));
	}
}