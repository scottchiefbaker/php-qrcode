<?php

// Standalone render benchmark. No PHPUnit, no Composer, no dependencies
// beyond PHP. Run with: php unit_tests/benchmark.php
//
// Compares SVG string rendering against the PNG path (GD rasterization plus
// imagepng deflate). Encoding is shared by both paths, so it is measured
// separately and also reported as the render-only portion.

error_reporting(E_ALL);

require __DIR__ . '/../qrcode.php';

$iterations = 100;
$scale = 4;

foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--help' || $arg === '-h') {
		echo "Usage: php unit_tests/benchmark.php [--iterations=N] [--scale=N]\n";
		echo "  --iterations=N  Iterations per measurement (default: 100)\n";
		echo "  --scale=N       Scale factor for the main comparison (default: 4)\n";
		exit(0);
	}
	if (preg_match('/^--iterations=(\d+)$/', $arg, $matches) && (int)$matches[1] >= 1) {
		$iterations = (int)$matches[1];
		continue;
	}
	if (preg_match('/^--scale=(\d+)$/', $arg, $matches) && (int)$matches[1] >= 1) {
		$scale = (int)$matches[1];
		continue;
	}
	fwrite(STDERR, "Unknown option: {$arg}\n");
	exit(2);
}

function bench(callable $fn, int $iterations): float {
	$fn();
	$start = hrtime(true);
	for ($i = 0; $i < $iterations; $i++) {
		$fn();
	}
	return (hrtime(true) - $start) / $iterations / 1e6;
}

function measure_svg(string $data, array $options): string {
	return (new QRCode($data, $options))->render_svg();
}

function measure_image(string $data, array $options): \GdImage {
	return (new QRCode($data, $options))->render_image();
}

function measure_png(string $data, array $options): string {
	$image = (new QRCode($data, $options))->render_image();
	ob_start();
	imagepng($image);
	return (string)ob_get_clean();
}

function format_bytes(int $bytes): string {
	return number_format($bytes) . ' B';
}

$has_gd = extension_loaded('gd') && function_exists('imagecreatetruecolor');
$encode_method = new ReflectionMethod(QRCode::class, 'encode_and_calculate_size');

$cases = [
	'short' => 'HELLO',
	'url' => 'https://example.com/some/path?q=hello&lang=en',
	'long' => str_repeat('The quick brown fox jumps over the lazy dog. ', 12),
];

echo "php-qrcode PNG vs SVG benchmark\n";
printf("PHP %s, GD %s, iterations=%d, scale=%d\n\n", PHP_VERSION, $has_gd ? 'loaded' : 'missing', $iterations, $scale);

printf("%-18s %11s %11s %9s %11s %11s\n", 'case', 'SVG total', 'PNG total', 'PNG/SVG', 'SVG bytes', 'PNG bytes');
printf("%'-18s %'-11s %'-11s %'-9s %'-11s %'-11s\n", '', '', '', '', '', '');

foreach ($cases as $name => $data) {
	$options = ['s' => 'qr-m', 'sf' => $scale];
	$label = sprintf('%s (%d chars)', $name, strlen($data));

	$encode_ms = bench(function () use ($data, $options, $encode_method) {
		$encode_method->invoke(new QRCode($data, $options), $data, $options);
	}, $iterations);
	$svg_ms = bench(fn() => measure_svg($data, $options), $iterations);

	$svg = measure_svg($data, $options);
	$svg_bytes = strlen($svg);

	if ($has_gd) {
		$image_ms = bench(fn() => measure_image($data, $options), $iterations);
		$png_ms = bench(fn() => measure_png($data, $options), $iterations);
		$png = measure_png($data, $options);
		$png_bytes = strlen($png);

		printf(
			"%-18s %8.3f ms %8.3f ms %8.2fx %11s %11s\n",
			$label,
			$svg_ms,
			$png_ms,
			$png_ms / $svg_ms,
			format_bytes($svg_bytes),
			format_bytes($png_bytes)
		);
		printf(
			"%-18s %8.3f ms %8.3f ms %8.3f ms\n",
			'  render-only',
			max(0.0, $svg_ms - $encode_ms),
			max(0.0, $image_ms - $encode_ms),
			max(0.0, $png_ms - $image_ms)
		);
		echo "\n";
	} else {
		printf("%-18s %8.3f ms %11s %9s %11s %11s\n", $label, $svg_ms, 'n/a', 'n/a', format_bytes($svg_bytes), 'n/a');
		echo "\n";
	}
}

if ($has_gd) {
	echo "Render-only columns: SVG string, GD rasterization, PNG deflate.\n\n";

	$scale_iterations = max(10, intdiv($iterations, 4));
	$data = 'https://example.com/some/path?q=hello&lang=en';

	printf("Scale sweep (url, s=qr-m, iterations=%d)\n", $scale_iterations);
	printf("%-6s %11s %11s %9s %11s %11s\n", 'scale', 'SVG total', 'PNG total', 'PNG/SVG', 'SVG bytes', 'PNG bytes');
	printf("%'-6s %'-11s %'-11s %'-9s %'-11s %'-11s\n", '', '', '', '', '', '');

	foreach ([4, 10, 20, 40] as $sweep_scale) {
		$options = ['s' => 'qr-m', 'sf' => $sweep_scale];
		$svg_ms = bench(fn() => measure_svg($data, $options), $scale_iterations);
		$png_ms = bench(fn() => measure_png($data, $options), $scale_iterations);

		printf(
			"%-6d %8.3f ms %8.3f ms %8.2fx %11s %11s\n",
			$sweep_scale,
			$svg_ms,
			$png_ms,
			$png_ms / $svg_ms,
			format_bytes(strlen(measure_svg($data, $options))),
			format_bytes(strlen(measure_png($data, $options)))
		);
	}
} else {
	echo "PNG benchmarks skipped: the GD extension is not available.\n";
}
