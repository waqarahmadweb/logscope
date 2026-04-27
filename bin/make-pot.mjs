#!/usr/bin/env node
/**
 * Regenerates `languages/logscope.pot` via WP-CLI's `i18n make-pot` command.
 *
 * WP-CLI is the only tool that scans both PHP `__()` calls and JS `__()` /
 * `_n()` calls in a single pass; the npm-only `wp-pot-cli` is PHP-only and
 * `@wordpress/scripts` does not bundle a `make-pot` subcommand. To avoid a
 * heavy `composer require wp-cli/wp-cli-bundle` (≈ 150 MB of dev deps), this
 * script downloads the official `wp-cli.phar` into a gitignored `tools/`
 * directory on first run and reuses it thereafter. PHP must be on PATH; the
 * plugin already requires PHP ≥ 8.0 for development.
 */
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import https from 'node:https';
import fs from 'node:fs';

const ROOT = resolve( fileURLToPath( import.meta.url ), '../..' );
const TOOLS_DIR = resolve( ROOT, 'tools' );
const PHAR_PATH = resolve( TOOLS_DIR, 'wp-cli.phar' );
const PHAR_URL =
	'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';

function ensurePhar() {
	if ( existsSync( PHAR_PATH ) ) {
		return;
	}
	if ( ! existsSync( TOOLS_DIR ) ) {
		mkdirSync( TOOLS_DIR, { recursive: true } );
	}
	process.stdout.write(
		`[make-pot] Downloading wp-cli.phar to ${ PHAR_PATH }…\n`
	);
	return new Promise( ( resolveDownload, rejectDownload ) => {
		const request = https.get( PHAR_URL, ( response ) => {
			if ( response.statusCode === 302 || response.statusCode === 301 ) {
				https.get( response.headers.location, follow );
				return;
			}
			follow( response );
		} );
		request.on( 'error', rejectDownload );

		function follow( res ) {
			if ( res.statusCode !== 200 ) {
				rejectDownload(
					new Error(
						`Download failed with HTTP ${ res.statusCode }`
					)
				);
				return;
			}
			const file = fs.createWriteStream( PHAR_PATH );
			res.pipe( file );
			file.on( 'finish', () => file.close( resolveDownload ) );
			file.on( 'error', rejectDownload );
		}
	} );
}

function checkPhp() {
	const result = spawnSync( 'php', [ '-v' ], {
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
	if ( result.status !== 0 ) {
		process.stderr.write(
			'[make-pot] PHP is not on PATH. Install PHP ≥ 8.0 to regenerate the .pot file.\n'
		);
		process.exit( 1 );
	}
}

await ensurePhar();
checkPhp();

execFileSync(
	'php',
	[
		PHAR_PATH,
		'i18n',
		'make-pot',
		ROOT,
		resolve( ROOT, 'languages/logscope.pot' ),
		'--slug=logscope',
		'--domain=logscope',
		'--package-name=Logscope',
	],
	{ stdio: 'inherit' }
);
