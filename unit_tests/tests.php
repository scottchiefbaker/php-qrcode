<?php

// Standalone unit tests. No PHPUnit, no Composer, no dependencies beyond PHP.
// Run with: php unit_tests/tests.php

error_reporting(E_ALL);

require __DIR__ . '/../qrcode.php';

// Registered tests: name, callable, required extension (or null)
$TESTS = [];

// Temp files created during the run, removed at the end
$TEMP_FILES = [];

function assert_svg(QRCode $qrcode): string {
	$svg = $qrcode->render_svg();
	assert_true(str_starts_with($svg, '<?xml version="1.0" encoding="UTF-8"?>'));
	assert_true(str_contains($svg, '<svg xmlns="http://www.w3.org/2000/svg"'));
	assert_true(str_contains($svg, '</svg>'));
	return $svg;
}

function svg_dimensions(string $svg): array {
	if (!preg_match('/width="(\d+)" height="(\d+)" viewBox="0 0 (\d+) (\d+)"/', $svg, $matches)) {
		fail_test('could not parse width, height and viewBox from SVG');
	}
	assert_same($matches[1], $matches[3], 'width agrees with viewBox');
	assert_same($matches[2], $matches[4], 'height agrees with viewBox');
	return [$matches[1], $matches[2]];
}

function svg_size(string $svg): int {
	$dimensions = svg_dimensions($svg);
	assert_same($dimensions[0], $dimensions[1], 'SVG is square');
	return (int)$dimensions[0];
}

test('SVG rendering produces a complete SVG document', function () {
	$svg = assert_svg(new QRCode('HELLO WORLD'));
	assert_true(str_contains($svg, 'fill="#FFFFFF"'), 'default background color');
	assert_true(str_contains($svg, 'fill="#000000"'), 'default foreground color');
});

test('SVG dimensions honor explicit width and height', function () {
	$svg = assert_svg(new QRCode('HELLO', ['w' => 240, 'h' => 120]));
	assert_true(str_contains($svg, 'width="240" height="120" viewBox="0 0 240 120"'));
});

test('SVG colors normalize shorthand and strip non-hex characters', function () {
	$svg = assert_svg(new QRCode('HELLO', ['bc' => '#abc', 'fc' => '12-34-56']));
	assert_true(str_contains($svg, 'fill="#AABBCC"'), 'normalized background color');
	assert_true(str_contains($svg, 'fill="#123456"'), 'normalized foreground color');
});

test('SVG scale and padding affect output dimensions', function () {
	$baseline = assert_svg(new QRCode('HELLO', ['sf' => 1]));
	$padded = assert_svg(new QRCode('HELLO', ['sf' => 1, 'p' => 5]));
	assert_true($baseline !== $padded, 'padding changes rendered geometry');
	assert_true(str_contains($baseline, 'width="29" height="29"'), 'baseline dimensions');
	assert_true(str_contains($padded, 'width="39" height="39"'), 'padding contributes to dimensions');
});

test('SVG error-correction separators are ignored', function () {
	$with_separator = assert_svg(new QRCode('HELLO', ['s' => 'qr-m']));
	$without_separator = assert_svg(new QRCode('HELLO', ['s' => 'qrm']));
	assert_same($with_separator, $without_separator);
});

test('unknown error correction falls back to level L', function () {
	$unknown = assert_svg(new QRCode('HELLO', ['s' => 'unknown']));
	$level_l = assert_svg(new QRCode('HELLO', ['s' => 'qr-l']));
	assert_same($level_l, $unknown);
});

test('empty data still renders a QR code', function () {
	$svg = assert_svg(new QRCode(''));
	assert_true(str_contains($svg, '<path d="'), 'encoded matrix is present');
});

test('numeric alphanumeric binary and Kanji data render', function () {
	$inputs = [
		'1234567890',
		'HELLO WORLD',
		'hello, world!',
		"\x8A\xBF\x8E\x9A", // Kanji for 漢字 in Shift-JIS
	];

	foreach ($inputs as $input) {
		assert_svg(new QRCode($input));
	}
});

test('nonpositive explicit dimensions remain at least one pixel', function () {
	$svg = assert_svg(new QRCode('HELLO', ['w' => 0, 'h' => -1]));
	assert_true(str_contains($svg, 'width="116" height="116"'));
});

test('PNG rendering returns a GD image of the requested dimensions', function () {
	$image = (new QRCode('HELLO', ['w' => 120, 'h' => 80]))->render_image();
	assert_same(120, imagesx($image));
	assert_same(80, imagesy($image));
}, 'gd');

test('higher QR versions render at spec-fixed sizes', function () {
	$cases = [
		[str_repeat('1', 50),   33],   // version 2: alignment patterns appear
		[str_repeat('1', 100),  37],   // version 3
		[str_repeat('1', 350),  53],   // version 7: version info appears
		[str_repeat('1', 600),  65],   // version 10: version group 1
		[str_repeat('1', 4500), 153],  // version 32: version group 2
		[str_repeat('1', 7000), 185],  // version 40: maximum
	];
	foreach ($cases as [$data, $size]) {
		$svg = assert_svg(new QRCode($data, ['sf' => 1]));
		assert_same($size, svg_size($svg), 'size for ' . strlen($data) . ' numeric characters');
	}
});

test('Kanji payloads reach high versions from half the byte count', function () {
	$svg = assert_svg(new QRCode(str_repeat("\x8A\xBF", 900), ['sf' => 1]));
	assert_same(133, svg_size($svg), 'version 27 Kanji payload');
});

test('all four error correction levels produce distinct output', function () {
	$rendered = [];
	foreach (['qr-l', 'qr-m', 'qr-q', 'qr-h'] as $level) {
		$rendered[] = assert_svg(new QRCode('HELLO WORLD', ['s' => $level]));
	}
	assert_same(4, count(array_unique($rendered)), 'each level renders differently');
});

test('payloads larger than version 40 are truncated, not rejected', function () {
	$svg = assert_svg(new QRCode(str_repeat('1', 7200), ['sf' => 1]));
	assert_same(185, svg_size($svg), 'oversized payload clamps to version 40');
});

test('per-side padding options each contribute to one dimension', function () {
	$base = ['sf' => 1];
	$square = fn (array $options): array => svg_dimensions((new QRCode('HELLO', $options))->render_svg());
	assert_same(['29', '29'], $square($base));
	assert_same(['29', '39'], $square($base + ['pv' => 5]));
	assert_same(['39', '29'], $square($base + ['ph' => 5]));
	assert_same(['29', '32'], $square($base + ['pt' => 3]));
	assert_same(['29', '32'], $square($base + ['pb' => 3]));
	assert_same(['32', '29'], $square($base + ['pl' => 3]));
	assert_same(['32', '29'], $square($base + ['pr' => 3]));
});

test('independent scale options stretch width and height', function () {
	assert_same(['58', '29'], svg_dimensions((new QRCode('HELLO', ['sf' => 1, 'sx' => 2]))->render_svg()));
	assert_same(['29', '58'], svg_dimensions((new QRCode('HELLO', ['sf' => 1, 'sy' => 2]))->render_svg()));
});

test('quiet zone and module width options change layout', function () {
	assert_same(['45', '45'], svg_dimensions((new QRCode('HELLO', ['sf' => 1, 'wq' => 3]))->render_svg()));
	assert_same(['50', '50'], svg_dimensions((new QRCode('HELLO', ['sf' => 1, 'wm' => 2]))->render_svg()));
});

test('invalid numeric options silently fall back to defaults', function () {
	$default = assert_svg(new QRCode('HELLO'));
	$fallbacks = [
		['sf' => 'abc'], ['sf' => 0], ['sf' => -1],
		['w' => 0], ['h' => -1], ['w' => 'x'], ['h' => 'x'],
		['p' => 'nope'], ['pv' => null], ['sx' => 0], ['md' => 'bad'],
	];
	foreach ($fallbacks as $index => $options) {
		assert_same($default, (new QRCode('HELLO', $options))->render_svg(), 'fallback case ' . $index);
	}
});

test('marker density option changes the rendered path', function () {
	$full = (new QRCode('HELLO', ['sf' => 4, 'md' => 1]))->render_svg();
	$half = (new QRCode('HELLO', ['sf' => 4, 'md' => 0.5]))->render_svg();
	assert_true($full !== $half, 'density alters rendering');
});

test('color options fall back and are truncated to six digits', function () {
	$svg = assert_svg(new QRCode('HELLO', ['bc' => '', 'fc' => ['x']]));
	assert_true(str_contains($svg, 'fill="#FFFFFF"'), 'empty background falls back to white');
	assert_true(str_contains($svg, 'fill="#000000"'), 'non-scalar foreground falls back to black');

	$svg = assert_svg(new QRCode('HELLO', ['bc' => '1122334455']));
	assert_true(str_contains($svg, 'fill="#112233"'), 'extra hex digits are dropped');

	$svg = assert_svg(new QRCode('HELLO', ['fc' => 'a1b2c3']));
	assert_true(str_contains($svg, 'fill="#A1B2C3"'), 'six hex digits pass through uppercased');
});

test('output_svg writes exactly the same document as render_svg', function () {
	$qrcode = new QRCode('HELLO', ['sf' => 1]);
	$output = capture_output(fn () => $qrcode->output_svg());
	assert_same($qrcode->render_svg(), $output);
});

test('output_image writes PNG bytes to the response', function () {
	$output = capture_output(fn () => (new QRCode('HELLO'))->output_image());
	assert_same("\x89PNG\r\n\x1a\n", substr($output, 0, 8), 'PNG signature');
}, 'gd');

test('rendering is deterministic for identical input and options', function () {
	$first = (new QRCode('REPEATABLE', ['sf' => 3, 'bc' => '#abc']))->render_svg();
	$second = (new QRCode('REPEATABLE', ['sf' => 3, 'bc' => '#abc']))->render_svg();
	assert_same($first, $second);
});

test('encoder output matches qrencode across versions and levels', function () {
	$qrencode = find_executable('qrencode');
	if ($qrencode === null) {
		skip_test('qrencode binary not found');
	}

	$cases = [
		['HELLO WORLD', 'L'],
		['1234567890', 'L'],
		['hello, world!', 'L'],
		['HELLO WORLD', 'M'],
		['HELLO WORLD', 'Q'],
		['HELLO WORLD', 'H'],
		['The quick brown fox jumps over the lazy dog', 'L'],
		[str_repeat('A', 300), 'L'],
		[str_repeat('1', 600), 'L'],
	];

	foreach ($cases as [$data, $level]) {
		$qrcode = new QRCode($data, ['s' => 'qr-' . strtolower($level), 'sf' => 1]);
		$ours = image_matrix($qrcode->render_image());

		$reference_png = temp_file('qrencode_', '.png');
		$command = $qrencode
			. ' -s 1 -m 4 -l ' . escapeshellarg($level)
			. ' -o ' . escapeshellarg($reference_png)
			. ' ' . escapeshellarg($data);
		exec($command, $ignored, $status);
		assert_same(0, $status, 'qrencode exit status');

		$reference = image_matrix(imagecreatefrompng($reference_png));
		$label = strlen($data) . ' bytes at level ' . $level;

		assert_same(count($reference), count($ours), $label . ': symbol size');
		assert_same(
			unmasked_data_bits($qrcode, $ours, symbol_mask($ours)),
			unmasked_data_bits($qrcode, $reference, symbol_mask($reference)),
			$label . ': unmasked codewords'
		);
	}
}, 'gd');

class AssertionFailed extends \Exception { }

class TestSkipped extends \Exception { }

function skip_test(string $reason): void {
	throw new TestSkipped($reason);
}

function capture_output(callable $fn): string {
	// header() warns once the runner has already printed earlier results.
	$reporting = error_reporting(0);
	try {
		ob_start();
		$fn();
		return (string)ob_get_clean();
	} finally {
		error_reporting($reporting);
	}
}

function find_executable(string $name): ?string {
	if (!function_exists('exec')) {
		return null;
	}
	$output = [];
	$status = 1;
	exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $output, $status);
	if ($status !== 0 || !isset($output[0]) || $output[0] === '') {
		return null;
	}
	return $output[0];
}

function temp_file(string $prefix = 'test_', string $suffix = ''): string {
	global $TEMP_FILES;
	$file = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8)) . $suffix;
	$TEMP_FILES[] = $file;
	return $file;
}

function image_matrix(\GdImage $image): array {
	$matrix = [];
	for ($y = 4, $height = imagesy($image) - 4; $y < $height; $y++) {
		for ($x = 4, $width = imagesx($image) - 4; $x < $width; $x++) {
			$value = imagecolorat($image, $x, $y);
			if (imageistruecolor($image)) {
				$luma = ((($value >> 16) & 0xFF) + (($value >> 8) & 0xFF) + ($value & 0xFF)) / 3;
			} else {
				$parts = imagecolorsforindex($image, $value);
				$luma = ($parts['red'] + $parts['green'] + $parts['blue']) / 3;
			}
			$matrix[$y - 4][$x - 4] = $luma > 127 ? 0 : 1;
		}
	}
	return $matrix;
}

function symbol_mask(array $matrix): int {
	$positions = [
		[8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
		[7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
	];
	$bits = '';
	foreach ($positions as [$row, $column]) {
		$bits .= $matrix[$row][$column];
	}

	$reflection = new \ReflectionClass(QRCode::class);
	$formats = $reflection->getProperty('qr_format_info');
	foreach ($formats->getValue(new QRCode('')) as $index => $format) {
		if (implode('', $format) === $bits) {
			return $index % 8;
		}
	}
	fail_test('unrecognized format information in rendered symbol');
}

function unmasked_data_bits(QRCode $qrcode, array $matrix, int $mask): string {
	$size = count($matrix);
	$version = intdiv($size - 17, 4);

	$reflection = new \ReflectionClass(QRCode::class);
	[, $occupancy] = $reflection->getMethod('qr_create_matrix')->invoke($qrcode, $version, []);
	$mask_function = $reflection->getMethod('qr_mask');

	$bits = '';
	$row = $size - 1;
	$column = $size - 1;
	$direction = -1;
	while ($column > 0) {
		foreach ([0, 1] as $offset) {
			$c = $column - $offset;
			if (!$occupancy[$row][$c]) {
				$bits .= $matrix[$row][$c] ^ ($mask_function->invoke($qrcode, $mask, $row, $c) ? 1 : 0);
			}
		}
		$row += $direction;
		if ($row < 0 || $row >= $size) {
			$direction = -$direction;
			$row += $direction;
			$column -= 2;
			if ($column === 6) {
				$column--;
			}
		}
	}
	return $bits;
}

function fail_test(string $msg): void {
	throw new AssertionFailed($msg);
}

function export($value): string {
	return var_export($value, true);
}

function assert_same($expected, $actual, string $msg = ''): void {
	if ($expected !== $actual) {
		$label = $msg !== '' ? "$msg: " : '';
		fail_test($label . 'expected ' . export($expected) . ', got ' . export($actual));
	}
}

function assert_true($actual, string $msg = ''): void {
	assert_same(true, $actual, $msg);
}

function assert_contains($needle, array $haystack, string $msg = ''): void {
	if (!in_array($needle, $haystack, true)) {
		$label = $msg !== '' ? "$msg: " : '';
		fail_test($label . 'expected array to contain ' . export($needle));
	}
}

function test(string $name, callable $fn, ?string $ext = null): void {
	global $TESTS;
	$TESTS[] = ['name' => $name, 'fn' => $fn, 'ext' => $ext];
}

// Colorize output when writing to a terminal (disable with NO_COLOR or --no-color)
$use_color = function_exists('posix_isatty') ? posix_isatty(STDOUT) : stream_isatty(STDOUT);
if (getenv('NO_COLOR') !== false || in_array('--no-color', $argv, true)) {
	$use_color = false;
}

$simple = in_array('--simple', $argv, true);

function paint(string $text, string $code, bool $on): string {
	return $on ? "\033[{$code}m{$text}\033[0m" : $text;
}

const NAME_WIDTH = 72;

function report(string $name, string $result, string $code, bool $on): void {
	if (strlen($name) > NAME_WIDTH) {
		$name = substr($name, 0, NAME_WIDTH);
	}
	echo str_pad($name, NAME_WIDTH) . '  ' . paint('[ ', '97', $on)
		. paint($result, $code, $on) . paint(' ]', '97', $on) . "\n";
}

$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($TESTS as $registered_test) {
	if ($registered_test['ext'] !== null && !extension_loaded($registered_test['ext'])) {
		if (!$simple) {
			report($registered_test['name'], 'SKIP', '33', $use_color);
			echo '      ' . paint("ext {$registered_test['ext']} not loaded", '33', $use_color) . "\n";
		}
		$skipped++;
		continue;
	}

	try {
		($registered_test['fn'])();
		if (!$simple) {
			report($registered_test['name'], 'PASS', '32', $use_color);
		}
		$passed++;
	} catch (TestSkipped $error) {
		if (!$simple) {
			report($registered_test['name'], 'SKIP', '33', $use_color);
			echo '      ' . paint($error->getMessage(), '33', $use_color) . "\n";
		}
		$skipped++;
	} catch (AssertionFailed $error) {
		report($registered_test['name'], 'FAIL', '31;1', $use_color);
		echo '      ' . paint($error->getMessage(), '31', $use_color) . "\n";
		$failed++;
	} catch (\Throwable $error) {
		report($registered_test['name'], 'ERROR', '31;1', $use_color);
		echo '      ' . paint(get_class($error) . ': ' . $error->getMessage(), '31', $use_color) . "\n";
		$failed++;
	}
}

foreach ($TEMP_FILES as $file) {
	foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
		if (file_exists($file . $suffix)) {
			@unlink($file . $suffix);
		}
	}
}

echo "\n";
$summary = "Passed: $passed  Failed: $failed  Skipped: $skipped";
if ($failed > 0) {
	echo paint($summary, '31;1', $use_color) . "\n";
} else {
	echo paint($summary, '32;1', $use_color) . "\n";
}

exit($failed > 0 ? 1 : 0);
