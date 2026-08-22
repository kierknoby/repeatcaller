<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

final class AlertCallPromptInventory {
	private const AUDIO_EXTENSIONS = ['alaw', 'g722', 'g729', 'gsm', 'sln', 'sln16', 'ulaw', 'wav', 'wav49'];

	/**
	 * @return array<int,string>|null
	 */
	public static function discover(string $soundsRoot, string $language): ?array {
		$language = trim($language);
		if ($language === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $language) !== 1 || !is_dir($soundsRoot)) {
			return null;
		}

		$normalisedLanguage = str_replace('-', '_', $language);
		$languageDirectories = array_values(array_unique([
			$normalisedLanguage,
			explode('_', $normalisedLanguage, 2)[0],
		]));
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
		$prompts = [];
		$foundLanguageDirectory = false;
		foreach ($languageDirectories as $languageDirectory) {
			$directory = $soundsRoot . '/' . $languageDirectory;
			if (!is_dir($directory)) {
				$directory = $availableDirectories[strtolower($languageDirectory)] ?? null;
				if ($directory === null) {
					continue;
				}
			}
			$foundLanguageDirectory = true;
			try {
				$files = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
					\RecursiveIteratorIterator::LEAVES_ONLY
				);
			} catch (\UnexpectedValueException $e) {
				continue;
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
		}

		return $foundLanguageDirectory ? array_keys($prompts) : null;
	}
}