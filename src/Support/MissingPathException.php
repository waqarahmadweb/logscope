<?php
/**
 * Exception thrown when a path is rejected because it does not exist.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Support;

/**
 * Specialisation of {@see InvalidPathException} for the "candidate path
 * does not exist (or is not accessible)" rejection branch. Callers that
 * care about distinguishing "missing yet" from "actively unsafe"
 * (e.g. the Settings UI's `/test-path` probe, which falls back to a
 * parent-directory check on a fresh install) can branch on the type
 * rather than on the human-readable message string. The message itself
 * remains free to evolve / be translated without silently breaking the
 * fallback contract.
 */
final class MissingPathException extends InvalidPathException {
}
