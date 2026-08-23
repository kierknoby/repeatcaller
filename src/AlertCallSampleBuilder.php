<?php

declare(strict_types=1);

namespace FreePBX\modules\Repeatcaller;

require_once __DIR__ . '/AlertCallLanguageSupport.php';
require_once __DIR__ . '/AlertCallPromptResolver.php';

final class AlertCallSampleBuilder {
	private const SCENARIOS = ['repeat', 'invert', 'acceptance'];
	private const AUDIO_EXTENSIONS = ['wav', 'ulaw', 'alaw', 'sln', 'sln16'];

	private string $soundsRoot;
	private AlertCallLanguageSupport $languageSupport;
	private AlertCallPromptResolver $promptResolver;

	public function __construct(
		string $soundsRoot,
		?AlertCallLanguageSupport $languageSupport = null,
		?AlertCallPromptResolver $promptResolver = null
	) {
		$this->soundsRoot = rtrim($soundsRoot, '/');
		$this->languageSupport = $languageSupport ?? new AlertCallLanguageSupport($this->soundsRoot);
		$this->promptResolver = $promptResolver ?? new AlertCallPromptResolver();
	}

	/**
	 * @return array{available:bool,language:string,scenario:string,clips:array<int,string>}
	 */
	public function build(string $requestedLanguage, string $scenario): array {
		$scenario = strtolower(trim($scenario));
		if (!in_array($scenario, self::SCENARIOS, true)) {
			return $this->unavailable($scenario);
		}

		$selection = $this->languageSupport->resolve($requestedLanguage);
		if (empty($selection['available'])) {
			return $this->unavailable($scenario);
		}

		$language = (string)($selection['language'] ?? '');
		$profile = (string)($selection['profile'] ?? '');
		$segments = $this->promptResolver->summarySegments([
				'summary_mode' => $scenario,
				'summary_call_count' => 3,
				'summary_threshold' => 2,
				'summary_window_minutes' => 5,
				'summary_caller_kind' => 'none',
				'summary_caller_value' => '',
				'summary_did_value' => '',
			], $profile);
		if ($scenario === 'acceptance') {
			$segments = array_slice($segments, -1);
		}

		$clips = [];
		foreach ($segments as $segment) {
			$prompt = $this->segmentPrompt($segment);
			$clip = $prompt !== '' ? $this->audioDataUri($language, $prompt) : '';
			if ($clip === '') {
				return $this->unavailable($scenario);
			}
			$clips[] = $clip;
		}

		return ['available' => true, 'language' => $language, 'scenario' => $scenario, 'clips' => $clips];
	}

	private function segmentPrompt(array $segment): string {
		$type = (string)($segment['type'] ?? '');
		$value = (string)($segment['value'] ?? '');
		if ($type === 'stream') {
			return $value;
		}
		if (($type === 'number' || $type === 'digits') && preg_match('/^[0-9]$/', $value) === 1) {
			return 'digits/' . $value;
		}
		return '';
	}

	private function audioDataUri(string $language, string $prompt): string {
		if (
			preg_match('/^[A-Za-z0-9_.-]+$/', $language) !== 1
			|| preg_match('#^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$#', $prompt) !== 1
		) {
			return '';
		}

		$base = $this->soundsRoot . '/' . $language . '/' . $prompt;
		foreach (self::AUDIO_EXTENSIONS as $extension) {
			$path = $base . '.' . $extension;
			if (!is_file($path) || !is_readable($path)) {
				continue;
			}
			$audio = file_get_contents($path);
			if ($audio === false || $audio === '') {
				continue;
			}
			$wav = $this->asWav($audio, $extension);
			if ($wav !== '') {
				return 'data:audio/wav;base64,' . base64_encode($wav);
			}
		}
		return '';
	}

	private function asWav(string $audio, string $extension): string {
		if ($extension === 'wav') {
			return strlen($audio) >= 44 && substr($audio, 0, 4) === 'RIFF' && substr($audio, 8, 4) === 'WAVE' ? $audio : '';
		}
		if ($extension === 'ulaw' || $extension === 'alaw') {
			$pcm = '';
			$length = strlen($audio);
			for ($offset = 0; $offset < $length; $offset++) {
				$sample = $extension === 'ulaw'
					? $this->decodeUlaw(ord($audio[$offset]))
					: $this->decodeAlaw(ord($audio[$offset]));
				$pcm .= pack('v', $sample & 0xffff);
			}
			return $this->wav($pcm, 8000);
		}
		if ($extension === 'sln' || $extension === 'sln16') {
			return strlen($audio) % 2 === 0 ? $this->wav($audio, $extension === 'sln16' ? 16000 : 8000) : '';
		}
		return '';
	}

	private function wav(string $pcm, int $sampleRate): string {
		$dataLength = strlen($pcm);
		return 'RIFF' . pack('V', 36 + $dataLength) . 'WAVEfmt '
			. pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16)
			. 'data' . pack('V', $dataLength) . $pcm;
	}

	private function decodeUlaw(int $value): int {
		$value = ~$value & 0xff;
		$sample = (($value & 0x0f) << 3) + 0x84;
		$sample <<= ($value & 0x70) >> 4;
		return ($value & 0x80) !== 0 ? 0x84 - $sample : $sample - 0x84;
	}

	private function decodeAlaw(int $value): int {
		$value ^= 0x55;
		$sample = ($value & 0x0f) << 4;
		$segment = ($value & 0x70) >> 4;
		$sample += $segment === 0 ? 8 : 0x108;
		if ($segment > 1) {
			$sample <<= $segment - 1;
		}
		return ($value & 0x80) !== 0 ? $sample : -$sample;
	}

	/**
	 * @return array{available:bool,language:string,scenario:string,clips:array<int,string>}
	 */
	private function unavailable(string $scenario): array {
		return ['available' => false, 'language' => '', 'scenario' => $scenario, 'clips' => []];
	}
}