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

function svg_path(string $svg): string {
	if (!preg_match('/<path d="([^"]*)"/', $svg, $matches)) {
		fail_test('could not find path data in SVG');
	}
	return $matches[1];
}

function svg_path_subpaths(string $path): int {
	return substr_count($path, 'M') + substr_count($path, 'm');
}

function svg_path_rects(string $path): array {
	preg_match_all('/[MmHhVvZz]|-?\d+(?:\.\d+)?/', $path, $matches);
	$tokens = $matches[0];
	$count = count($tokens);
	$rects = [];
	$x = $y = $start_x = $start_y = 0.0;
	$min_x = $min_y = $max_x = $max_y = null;
	$flush = function () use (&$rects, &$min_x, &$min_y, &$max_x, &$max_y): void {
		if ($min_x !== null) {
			$rects[] = [$min_x, $min_y, $max_x, $max_y];
			$min_x = $min_y = $max_x = $max_y = null;
		}
	};

	for ($i = 0; $i < $count;) {
		$cmd = $tokens[$i++];
		switch ($cmd) {
			case 'M':
			case 'm':
				$flush();
				$vx = (float)$tokens[$i++];
				$vy = (float)$tokens[$i++];
				if ($cmd === 'm') {
					$x += $vx;
					$y += $vy;
				} else {
					$x = $vx;
					$y = $vy;
				}
				$start_x = $x;
				$start_y = $y;
				$min_x = $max_x = $x;
				$min_y = $max_y = $y;
				break;
			case 'H':
			case 'h':
				$vx = (float)$tokens[$i++];
				$x = ($cmd === 'h') ? $x + $vx : $vx;
				$min_x = min($min_x, $x);
				$max_x = max($max_x, $x);
				break;
			case 'V':
			case 'v':
				$vy = (float)$tokens[$i++];
				$y = ($cmd === 'v') ? $y + $vy : $vy;
				$min_y = min($min_y, $y);
				$max_y = max($max_y, $y);
				break;
			case 'Z':
			case 'z':
				$x = $start_x;
				$y = $start_y;
				break;
			default:
				fail_test('unexpected SVG path command: ' . $cmd);
		}
	}
	$flush();
	return $rects;
}

function svg_path_cells(string $path): array {
	$rects = svg_path_rects($path);
	$cells = [];
	// SVG path coordinates include the quiet-zone offset; shift everything
	// so data modules start at (0, 0), matching the rendered matrix.
	$base_x = $rects ? min(array_column($rects, 0)) : 0;
	$base_y = $rects ? min(array_column($rects, 1)) : 0;
	foreach ($rects as [$min_x, $min_y, $max_x, $max_y]) {
		for ($y = (int)($min_y - $base_y); $y < (int)($max_y - $base_y); $y++) {
			for ($x = (int)($min_x - $base_x); $x < (int)($max_x - $base_x); $x++) {
				$cells[$y][$x] = true;
			}
		}
	}
	ksort($cells);
	foreach ($cells as &$row) {
		ksort($row);
	}
	return $cells;
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
		"\xE0\x40", // Kanji in the 0xE0-0xEA lead-byte range
		"\xEB\xBF", // Kanji with the 0xEB lead byte
	];

	foreach ($inputs as $input) {
		assert_svg(new QRCode($input));
	}
});

test('mode detection emits the right mode indicator in the codewords', function () {
	// The first four bits of each codeword stream are the mode indicator:
	// 0001 numeric, 0010 alphanumeric, 0100 binary, 1000 Kanji.
	$cases = [
		['',                             '0001', 'empty data uses numeric mode'],
		['1234567890',                   '0001', 'digits use numeric mode'],
		['HELLO WORLD',                  '0010', 'uppercase text uses alphanumeric mode'],
		["\xE0\x40",                     '1000', 'Shift-JIS pair uses Kanji mode'],
		["\x81\x40A",                    '0100', 'Kanji plus invalid byte falls back to binary mode'],
		["\x00",                         '0100', 'NUL byte uses binary mode'],
		["\xE6\xBC\xE6\xBC",             '1000', 'UTF-8 bytes can sneak into Kanji mode (documented limitation)'],
		["\xE6\xBC\xA2",                 '0100', 'odd-length UTF-8 falls back to binary mode'],
	];
	foreach ($cases as [$data, $mode_bits, $label]) {
		$qrcode = new QRCode($data, ['sf' => 1]);
		$matrix = image_matrix($qrcode->render_image());
		$bits = unmasked_data_bits($qrcode, $matrix, symbol_mask($matrix));
		assert_true(str_starts_with($bits, $mode_bits), $label . ' (got ' . substr($bits, 0, 4) . ')');
	}
}, 'gd');

test('nonpositive explicit dimensions remain at least one pixel', function () {
	$svg = assert_svg(new QRCode('HELLO', ['w' => 0, 'h' => -1]));
	assert_true(str_contains($svg, 'width="116" height="116"'));
});

test('PNG rendering returns a GD image of the requested dimensions', function () {
	$image = (new QRCode('HELLO', ['w' => 120, 'h' => 80]))->render_image();
	assert_same(120, imagesx($image));
	assert_same(80, imagesy($image));
}, 'gd');

test('PNG rendering applies background and foreground colors', function () {
	$image = (new QRCode('HELLO', ['sf' => 1, 'bc' => '112233', 'fc' => 'AABBCC']))->render_image();
	assert_same(0x112233, imagecolorat($image, 0, 0), 'background color');
	assert_same(0xAABBCC, imagecolorat($image, 4, 4), 'foreground color');
}, 'gd');

test('PNG module grid matches SVG module coverage', function () {
	$options = ['sf' => 1, 's' => 'qr-m'];
	$cells = svg_path_cells(svg_path((new QRCode('HELLO WORLD', $options))->render_svg()));

	$image = (new QRCode('HELLO WORLD', $options))->render_image();
	$png_cells = [];
	for ($y = 4, $size = imagesy($image) - 4; $y < $size; $y++) {
		for ($x = 4, $width = imagesx($image) - 4; $x < $width; $x++) {
			if (imagecolorat($image, $x, $y) >> 16 < 128) {
				$png_cells[$y - 4][$x - 4] = true;
			}
		}
	}
	assert_same(sha1(serialize($cells)), sha1(serialize($png_cells)),
		'both renderers draw the same modules at the same coordinates');
}, 'gd');

test('PNG scales in whole pixels and centers the symbol in the request', function () {
	// HELLO is version 1: 29 module units. In a 120x80 box the natural
	// fit ratio 120/29 floors to 2, so the 58x58 symbol is centered at
	// (31, 11) and the four-module quiet zone starts at (39, 19).
	$fg = 0x000000;
	$bg = 0xFFFFFF;
	$image = (new QRCode('HELLO', ['w' => 120, 'h' => 80]))->render_image();
	assert_same(120, imagesx($image));
	assert_same(80, imagesy($image));
	assert_same($fg, imagecolorat($image, 39, 19), 'first module sits at the computed offset');
	assert_same($fg, imagecolorat($image, 40, 20), 'modules draw a full 2px wide');
	assert_same($bg, imagecolorat($image, 38, 19), 'no antialiasing at module edges');
	assert_same($bg, imagecolorat($image, 53, 19), 'separator column stays light after the finder row');
	assert_same($bg, imagecolorat($image, 5, 5), 'centering leaves unmarked background margins');
	assert_same($bg, imagecolorat($image, 115, 75), 'the wide aspect leaves extra margin on the right');
}, 'gd');

test('PNG marker density insets dark modules like the SVG renderer', function () {
	// Default 4px modules at 50% density draw as a 2x2 block inset by
	// one pixel from the top-left corner of each module cell.
	$fg = 0x000000;
	$bg = 0xFFFFFF;
	$image = (new QRCode('HELLO', ['md' => 0.5]))->render_image();
	assert_same(116, imagesx($image));
	assert_same(116, imagesy($image));
	assert_same($fg, imagecolorat($image, 17, 17), 'inset dark block covers the center');
	assert_same($fg, imagecolorat($image, 18, 18), 'inset dark block is 2px');
	assert_same($bg, imagecolorat($image, 16, 16), 'cell origin is left empty at reduced density');
	assert_same($bg, imagecolorat($image, 19, 19), 'cell tail is left empty at reduced density');
}, 'gd');

test('higher QR versions render at spec-fixed sizes', function () {
	$cases = [
		[str_repeat('1', 50),   33],   // version 2: alignment patterns appear
		[str_repeat('1', 100),  37],   // version 3
		[str_repeat('1', 350),  53],   // version 7: version info appears
		[str_repeat('1', 600),  65],   // version 10: version group 1
		[str_repeat('1', 2000), 105],  // version 20
		[str_repeat('1', 4500), 153],  // version 32: version group 2
		[str_repeat('1', 7000), 185],  // version 40: maximum
	];
	foreach ($cases as [$data, $size]) {
		$svg = assert_svg(new QRCode($data, ['sf' => 1]));
		assert_same($size, svg_size($svg), 'size for ' . strlen($data) . ' numeric characters');
	}
});

test('version information blocks carry valid BCH checksums', function () {
	// The 18-bit version information must decode to the version number and
	// satisfy the BCH(18,6) checksum (generator 0x1F25) in both placements.
	$bch = function (array $matrix): int {
		$size = count($matrix);
		$bits_bottom = '';
		$bits_top = '';
		for ($i = 0; $i < 18; $i++) {
			// Bottom-left block: rows size-11..size-9, columns 5..0.
			// Top-right block is the mirrored transpose.
			$r = $size - 9 - ($i % 3);
			$c = 5 - intdiv($i, 3);
			$bits_bottom .= $matrix[$r][$c];
			$bits_top .= $matrix[$c][$r];
		}
		assert_same($bits_bottom, $bits_top, 'both placements hold identical bits');
		return bindec($bits_bottom);
	};

	$cases = [
		[str_repeat('1', 350),  7],   // first version with version info
		[str_repeat('1', 600),  10],
		[str_repeat('1', 2000), 20],
		[str_repeat('1', 4500), 32],  // second length-field group
		[str_repeat('1', 7000), 40],  // maximum
	];
	foreach ($cases as [$data, $version]) {
		$matrix = image_matrix((new QRCode($data, ['sf' => 1]))->render_image());
		assert_same(17 + 4 * $version, count($matrix), "symbol is version $version");

		$value = $bch($matrix);
		$decoded = $value >> 12;
		assert_same($version, $decoded, 'data bits decode to the version number');
		assert_same(($decoded << 12) | bch12($decoded), $value, "BCH checksum for version $version");
	}
}, 'gd');

test('fixed structural modules are placed per spec across versions', function () {
	// Independent re-derivation of the finder, separator, timing, alignment
	// and dark-module templates straight from the QR specification.
	$finder_bit = static fn (int $dy, int $dx): int =>
		(($dy === 0 || $dy === 6 || $dx === 0 || $dx === 6)
			|| !($dy === 1 || $dy === 5 || $dx === 1 || $dx === 5)) ? 1 : 0;
	$alignment_bit = static fn (int $dy, int $dx): int =>
		((max(abs($dy), abs($dx)) & 1) === 0) ? 1 : 0;

	$cases = [
		['HELLO WORLD',        21, []],        // version 1
		[str_repeat('1', 50),  25, [[18, 18]]], // version 2 alignment center
		[str_repeat('1', 350), 45, [[6, 22], [22, 6], [22, 22]]], // version 7
	];
	foreach ($cases as [$data, $size, $centers]) {
		$matrix = image_matrix((new QRCode($data, ['sf' => 1]))->render_image());
		assert_same($size, count($matrix), 'symbol size');
		$last = $size - 1;

		// The mandatory dark module at row size-8, column 8.
		assert_true($matrix[$size - 8][8] === 1, "size $size: dark module is dark");

		// Timing patterns alternate along row and column 6, between the
		// finder areas only.
		for ($i = 8; $i <= $size - 9; $i++) {
			assert_same(($i % 2 === 0) ? 1 : 0, $matrix[6][$i], "size $size: timing row at column $i");
			assert_same(($i % 2 === 0) ? 1 : 0, $matrix[$i][6], "size $size: timing column at row $i");
		}

		// Finder patterns sit in three corners with light separators.
		foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r0, $c0]) {
			for ($dy = 0; $dy <= 6; $dy++) {
				for ($dx = 0; $dx <= 6; $dx++) {
					assert_same($finder_bit($dy, $dx), $matrix[$r0 + $dy][$c0 + $dx],
						"size $size: finder template at $r0,$c0 offset $dy,$dx");
				}
			}
		}
		// Shifted copies of the top-left separator for the other corners.
		for ($i = 0; $i <= 7; $i++) {
			assert_true($matrix[7][$i] === 0, "size $size: separator row 7, column $i");
			assert_true($matrix[$i][7] === 0, "size $size: separator column 7, row $i");
			assert_true($matrix[7][$last - $i] === 0, "size $size: separator row 7, column -$i");
			assert_true($matrix[$i][$last - 7] === 0, "size $size: separator column size-8, row $i");
			assert_true($matrix[$last - 7][$i] === 0, "size $size: separator row size-8, column $i");
			assert_true($matrix[$last - $i][7] === 0, "size $size: separator column 7, row -$i");
		}

		// Alignment patterns (skipped where they overlap finders).
		foreach ($centers as [$r, $c]) {
			for ($dy = -2; $dy <= 2; $dy++) {
				for ($dx = -2; $dx <= 2; $dx++) {
					assert_same($alignment_bit($dy, $dx), $matrix[$r + $dy][$c + $dx],
						"size $size: alignment template at $r,$c offset $dy,$dx");
				}
			}
		}
	}
}, 'gd');

test('mask penalty rules score runs, blocks, patterns and density per spec', function () {
	// The penalty functions classify a module as dark when its raw matrix
	// value is 3 or 5, so hand-built matrices use 3 for dark and 0 for
	// light. Expected scores are derived straight from the specification.
	$qrcode = new QRCode('');
	$score = function (string $method, array $matrix) use ($qrcode): int {
		$reflection = new \ReflectionMethod(QRCode::class, $method);
		$closure = $reflection->getClosure($qrcode);
		return $closure($matrix, count($matrix));
	};
	$grid = function (array $rows): array {
		$matrix = [];
		foreach ($rows as $row) {
			$matrix[] = array_map(static fn (string $c): int => $c === '1' ? 3 : 0, str_split($row));
		}
		return $matrix;
	};
	$checker = static function (int $size): array {
		$matrix = [];
		for ($i = 0; $i < $size; $i++) {
			$row = [];
			for ($j = 0; $j < $size; $j++) $row[] = (($i + $j) % 2) ? 0 : 3;
			$matrix[] = $row;
		}
		return $matrix;
	};

	// Rule 1: every run of one colour of length N >= 5 scores N - 2.
	$all_dark = $grid(array_fill(0, 5, '11111'));
	$all_light = $grid(array_fill(0, 5, '00000'));
	assert_same(30, $score('qr_penalty_1', $all_dark), 'rule 1: 5x5 single colour dark (10 runs of 5)');
	assert_same(30, $score('qr_penalty_1', $all_light), 'rule 1: light runs score the same as dark runs');
	$single_run = $grid([
		'0000000', '0000000', '0000000', '1111100',
		'0000000', '0000000', '0000000',
	]);
	assert_same(43, $score('qr_penalty_1', $single_run),
		'rule 1: six full light rows (+5 each), the 5-run (+3), two full light columns (+5 each)');

	// Rule 2: every same-colour 2x2 block scores 3.
	assert_same(48, $score('qr_penalty_2', $all_dark), 'rule 2: 5x5 has 16 uniform blocks');
	assert_same(0, $score('qr_penalty_2', $checker(4)), 'rule 2: checkerboard never matches');

	// Rule 3: the 10111010000 / 00001011101 patterns score 40 each.
	$pattern_row = fn (string $row): array => array_map(static fn (string $c): int => $c === '1' ? 3 : 0, str_split($row));
	$pattern = $grid(array_fill(0, 11, '00000000000'));
	$pattern[5] = $pattern_row('10111010000');
	assert_same(40, $score('qr_penalty_3', $pattern), 'rule 3: 10111010000 row');
	$pattern[5] = $pattern_row('00001011101');
	assert_same(40, $score('qr_penalty_3', $pattern), 'rule 3: mirrored 00001011101 row');
	$pattern[5] = $pattern_row('01011010000');
	assert_same(0, $score('qr_penalty_3', $pattern), 'rule 3: an off-pattern row scores nothing');

	// Rule 4: dark-module ratio scored in 1% steps around 50%.
	$half = $checker(20); // one colour in half the cells: exactly 50%
	assert_same(0, $score('qr_penalty_4', $half), 'rule 4: 50% ratio scores zero');
	$full = $grid(array_fill(0, 20, str_repeat('1', 20)));
	assert_same(100, $score('qr_penalty_4', $full), 'rule 4: 100% dark ratio scores 10 steps');
	assert_same(100, $score('qr_penalty_4', $all_light), 'rule 4: 0% ratio mirrors the fully dark one');
});

test('alphanumeric and binary payloads span every length-field group', function () {
	$cases = [
		[str_repeat('A', 336),  65],  // alphanumeric version 10: length group 1
		[str_repeat('A', 1991), 133], // alphanumeric version 27: length group 2
		[str_repeat('a', 231),  65],  // binary version 10: 16-bit length field
		[str_repeat('a', 1368), 133], // binary version 27: 16-bit length field
	];
	foreach ($cases as [$data, $size]) {
		$svg = assert_svg(new QRCode($data, ['sf' => 1]));
		assert_same($size, svg_size($svg), 'size for ' . strlen($data) . ' bytes');
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

test('oversized Kanji payloads truncate on character boundaries', function () {
	$reflection = new \ReflectionClass(QRCode::class);
	$capacity = $reflection->getProperty('qr_capacity')->getValue(new QRCode(''));
	$max_chars = $capacity[39][0][3]; // version 40, level L, Kanji
	$payload = str_repeat("\x81\x40", $max_chars + 1);
	$svg = assert_svg(new QRCode($payload, ['sf' => 1]));
	assert_same(185, svg_size($svg), 'truncates to version 40 capacity');
	assert_true(count(svg_path_cells(svg_path($svg))) > 0, 'remaining data still encodes modules');
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

test('non-string data is rejected by the constructor', function () {
	$thrown = false;
	try {
		new QRCode(['x']);
	} catch (\TypeError) {
		$thrown = true;
	}
	assert_true($thrown, 'array data raises TypeError');
});

test('marker density option changes the rendered path', function () {
	$full = (new QRCode('HELLO', ['sf' => 4, 'md' => 1]))->render_svg();
	$half = (new QRCode('HELLO', ['sf' => 4, 'md' => 0.5]))->render_svg();
	assert_true($full !== $half, 'density alters rendering');
});

test('merged SVG path preserves module coverage and reduces size', function () {
	$merged = svg_path((new QRCode('HELLO', ['sf' => 1]))->render_svg());
	$unmerged = svg_path((new QRCode('HELLO', ['sf' => 1, 'md' => 0.999]))->render_svg());

	assert_same(
		sha1(serialize(svg_path_cells($unmerged))),
		sha1(serialize(svg_path_cells($merged))),
		'merged rectangles cover the same modules'
	);
	assert_true(svg_path_subpaths($merged) < svg_path_subpaths($unmerged), 'merging reduces subpaths');
	assert_true(strlen($merged) < strlen($unmerged) * 0.5, 'merging reduces path size');
});

test('emitted SVG subpaths trace well-formed rectangles', function () {
	// Simulate the pen through each subpath: the renderer draws every
	// rectangle as M x y (or m dx dy after the first), h w, v h and one
	// close command that returns to the rectangle corner it left from
	// along an edge, with the fill implicitly closing the one remaining
	// side.
	$data_cases = ['HELLO', 'https://example.com/some/path?q=hello&lang=en',
		'REPEATABLE', str_repeat('A', 40)];
	foreach ($data_cases as $data) {
		foreach ([1, 4] as $scale) {
			preg_match_all('/[MmHhVvZz]|-?\d+(?:\.\d+)?/', svg_path((new QRCode($data, ['s' => 'qr-m', 'sf' => $scale]))->render_svg()), $matches);
			$tokens = $matches[0];
			$count = count($tokens);
			assert_true($count > 0, 'path has commands');

			$i = 0;
			$subpaths = 0;
			$pen_x = 0.0;
			$pen_y = 0.0;
			while ($i < $count) {
				$cmd = $tokens[$i++];
				assert_true($cmd === 'M' || $cmd === 'm', 'every subpath starts with a move command');
				$vx = (float)$tokens[$i++];
				$vy = (float)$tokens[$i++];
				if ($cmd === 'm') {
					$pen_x += $vx;
					$pen_y += $vy;
					$start_x = $pen_x;
					$start_y = $pen_y;
				} else {
					$start_x = $vx;
					$start_y = $vy;
				}
				$cmd = $tokens[$i++];
				assert_same('h', $cmd, 'rectangle top edge is drawn rightward');
				$w = (float)$tokens[$i++];
				$cmd = $tokens[$i++];
				assert_same('v', $cmd, 'rectangle right edge is drawn downward');
				$h = (float)$tokens[$i++];
				assert_true($w > 0 && $h > 0, 'rectangle has positive extent');
				$cmd = $tokens[$i++];
				if ($cmd === 'h') {
					assert_same(-$w, (float)$tokens[$i++], 'close returns along the bottom edge');
					$pen_x = $start_x;
					$pen_y = $start_y + $h;
				} elseif ($cmd === 'v') {
					assert_same(-$h, (float)$tokens[$i++], 'close returns along the right edge');
					$pen_x = $start_x + $w;
					$pen_y = $start_y;
				} else {
					assert_true($cmd === 'H' || $cmd === 'V', 'close is one of h, H, v, V');
					if ($cmd === 'H') {
						assert_same($start_x, (float)$tokens[$i++], 'absolute close returns to the left edge');
						$pen_x = $start_x;
						$pen_y = $start_y + $h;
					} else {
						assert_same($start_y, (float)$tokens[$i++], 'absolute close returns to the top edge');
						$pen_x = $start_x + $w;
						$pen_y = $start_y;
					}
				}
				$subpaths++;
			}
			assert_true($subpaths > 0, 'path has subpaths');
		}
	}
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

test('HTTP endpoint renders SVG via f or format', function () {
	$expected = (new QRCode('HELLO', ['sf' => 1]))->render_svg();
	assert_same($expected, run_endpoint(['d' => 'HELLO', 'sf' => 1, 'f' => 'svg']));
	assert_same($expected, run_endpoint(['d' => 'HELLO', 'sf' => 1, 'format' => ' SVG ']));
});

test('HTTP endpoint defaults to PNG for missing or unknown formats', function () {
	$requests = [
		['d' => 'HELLO'],
		['d' => 'HELLO', 'f' => 'gif'],
		['d' => 'HELLO', 'f' => ['svg']],
	];
	foreach ($requests as $request) {
		$output = run_endpoint($request);
		assert_same("\x89PNG\r\n\x1a\n", substr($output, 0, 8), 'PNG signature');
	}
}, 'gd');

test('HTTP endpoint parses string-typed layout options like the class', function () {
	// Over HTTP every parameter arrives as a string; the endpoint and the
	// class API must produce the same symbol for the same options.
	$options = [
		'sf' => '1', 'bc' => 'abc', 'fc' => '12-34-56', 'md' => '0.5',
		'wq' => '2', 'wm' => '2', 'sx' => '2', 'sy' => '1',
		'pv' => '3', 'pl' => '1',
	];
	$expected = (new QRCode('HELLO', $options))->render_svg();
	assert_same($expected, run_endpoint($options + ['d' => 'HELLO', 'f' => 'svg']));
});

test('chosen mask patterns are a pinned regression sentinel', function () {
	// Mask selection follows from the penalty rules, which the previous
	// test validates against the spec; these pins (recorded from a verified
	// state) lock in the end-to-end result so a penalty or scoring
	// regression fails here even though the oracle comparison above would
	// still pass, since it reuses the symbol's own mask.
	$cases = [
		['HELLO WORLD', 'qr-l', 2],
		['HELLO WORLD', 'qr-m', 0],
		['HELLO WORLD', 'qr-q', 6],
		['HELLO WORLD', 'qr-h', 5],
		['https://example.com/some/path?q=hello&lang=en', 'qr-m', 4],
		['1234567890123456789012345678901234567890', 'qr-l', 4],
		['A' . str_repeat('B', 60), 'qr-q', 5],
	];
	foreach ($cases as [$data, $level, $expected_mask]) {
		$matrix = image_matrix((new QRCode($data, ['s' => $level, 'sf' => 1]))->render_image());
		assert_same($expected_mask, symbol_mask($matrix), 'mask for ' . strlen($data) . ' bytes at ' . $level);
	}
}, 'gd');

test('rendering is deterministic for identical input and options', function () {
	$first = (new QRCode('REPEATABLE', ['sf' => 3, 'bc' => '#abc']))->render_svg();
	$second = (new QRCode('REPEATABLE', ['sf' => 3, 'bc' => '#abc']))->render_svg();
	assert_same($first, $second);
});

/**
 * Cross-check one payload against the qrencode binary: both symbols must
 * have the same size and the same unmasked data codeword bits. Skips the
 * whole test when the binary is unavailable.
 */
function compare_with_qrencode(string $data, string $level, string $flags = ''): void {
	$qrencode = find_executable('qrencode');
	if ($qrencode === null) {
		skip_test('qrencode binary not found');
	}

	$qrcode = new QRCode($data, ['s' => 'qr-' . strtolower($level), 'sf' => 1]);
	$ours = image_matrix($qrcode->render_image());

	$reference_png = temp_file('qrencode_', '.png');
	// qrencode mangles non-ASCII bytes passed as a CLI argument; hand it
	// a raw file instead so byte payloads run through unmodified.
	if (strspn($data, ' -~') === strlen($data)) {
		$input = escapeshellarg($data);
	} else {
		$input_file = temp_file('qrencode_', '.bin');
		file_put_contents($input_file, $data);
		$input = '-r ' . escapeshellarg($input_file);
	}
	$command = $qrencode . ' ' . $flags
		. ' -s 1 -m 4 -l ' . escapeshellarg($level)
		. ' -o ' . escapeshellarg($reference_png)
		. ' ' . $input;
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

/**
 * BCH(18,6) remainder for a 6-bit version number, using the generator
 * 0x1F25 from the QR specification. Used to independently validate the
 * rendered version information bits.
 */
function bch12(int $data): int {
	$value = $data << 12;
	for ($i = 17; $i >= 12; $i--) {
		if ($value & (1 << $i)) {
			$value ^= 0x1F25 << ($i - 12);
		}
	}
	return $value & 0xFFF;
}

test('encoder output matches qrencode across versions and levels', function () {
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
		compare_with_qrencode($data, $level);
	}
}, 'gd');

test('oracle grid: every mode at multi-block versions and levels matches qrencode', function () {
	$reflection = new \ReflectionClass(QRCode::class);
	$capacity = $reflection->getProperty('qr_capacity')->getValue(new QRCode(''));
	$payload = [
		0 => fn (int $cap): string => str_repeat('1', $cap - 1),
		1 => fn (int $cap): string => str_repeat('A', $cap - 1),
		2 => fn (int $cap): string => str_repeat("\x01", $cap - 1),
		3 => fn (int $cap): string => str_repeat("\x81\x40", $cap),
	];
	$levels = ['L', 'H'];

	// Near-capacity payloads force single- and multi-block EC interleave:
	// version 1 uses one block per level, version 5 already splits into
	// two data blocks in some levels, and versions >= 20 use both block
	// groups with differing sizes.
	foreach ([1, 5, 10, 20, 40] as $version) {
		foreach ($levels as $level) {
			$ecl = [ 'L' => 0, 'H' => 3 ][$level];
			foreach ([0, 1, 2, 3] as $mode) {
				$data = $payload[$mode]($capacity[$version - 1][$ecl][$mode]);
				// qrencode only enters Kanji mode with an explicit -k flag.
				compare_with_qrencode($data, $level, $mode === 3 ? '-k' : '');
			}
		}
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

function run_endpoint(array $request): string {
	$entry = realpath(__DIR__ . '/../qrcode.php');
	$script = temp_file('endpoint_', '.php');
	$code = '<?php $_REQUEST = ' . var_export($request, true)
		. '; $_SERVER["SCRIPT_FILENAME"] = ' . var_export($entry, true)
		. '; require ' . var_export($entry, true) . ';';
	file_put_contents($script, $code);

	$process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	if (!is_resource($process)) {
		fail_test('could not start PHP endpoint');
	}
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	if ($status !== 0) {
		fail_test('endpoint failed: ' . trim($error));
	}
	return $output;
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
	if (file_exists($file)) {
		@unlink($file);
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
