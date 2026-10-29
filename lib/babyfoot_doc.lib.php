<?php
/* Copyright (C) 2026 ATM Consulting <support@atm-consulting.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    babyfoot/lib/babyfoot_doc.lib.php
 * \ingroup babyfoot
 * \brief   Markdown parsing and rendering for the Documentation admin tab.
 *
 * Separate from babyfoot.lib.php because team-ai-scaffold owns this file and rewrites it:
 * the hand-written lib must stay untouched. Kept out of the admin page so it can be required
 * from a test - that page calls llxHeader() and cannot be included.
 */
/**
 * Load the module documentation, one file per feature when that layout is present.
 *
 * Two sources, in order of preference:
 *  - docs/features/*.md, one file per feature, each opening with a YAML block naming it.
 *  - docs/configuration.md, the single hand-written file.
 *
 * Section anchors are prefixed with the file name: every feature file tends to open with the
 * same intro section title, so without a prefix the ids collide and every sidebar link jumps
 * to the first file.
 *
 * @param string $docsDir Absolute path to the module's docs directory
 * @return array{toc: array, sections: array, byFeature: bool}|null Null when no source exists
 */
function babyfootLoadDocumentation(string $docsDir): ?array
{
	$featureFiles = glob($docsDir . '/features/*.md');
	if (! is_array($featureFiles) || count($featureFiles) === 0) {
		$single = $docsDir . '/configuration.md';
		if (! file_exists($single)) {
			return null;
		}
		$parsed = babyfootParseMarkdown((string) file_get_contents($single));
		$parsed['byFeature'] = false;
		return $parsed;
	}

	sort($featureFiles);
	$toc = [];
	$sections = [];
	foreach ($featureFiles as $path) {
		$raw = (string) file_get_contents($path);
		// The stem reaches an HTML id attribute: keep it to the slug alphabet rather than
		// trusting the file name, which is whatever the docs author typed.
		$stem = babyfootSlugify(basename($path, '.md'));
		$label = babyfootFeatureLabel($raw, basename($path, '.md'));
		$parsed = babyfootParseMarkdown($raw, $stem . '-');

		$toc[] = ['level' => 2, 'text' => $label, 'id' => $stem];
		$sections[] = [
			'id' => $stem,
			'level' => 1,
			'html' => '<div class="aidoc-section aidoc-feature" id="' . htmlspecialchars($stem, ENT_QUOTES, 'UTF-8') . '"><h1>'
				. htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</h1></div>',
		];
		foreach ($parsed['toc'] as $entry) {
			if ($entry['level'] === 2) {
				$toc[] = ['level' => 3, 'text' => $entry['text'], 'id' => $entry['id']];
			}
		}
		$sections = array_merge($sections, $parsed['sections']);
	}

	return ['toc' => $toc, 'sections' => $sections, 'byFeature' => true];
}

/**
 * Read the feature name from a feature file's YAML block.
 *
 * @param string $raw  Raw file content, YAML block included
 * @param string $stem File name without extension, used when the block says nothing
 * @return string Feature name
 */
function babyfootFeatureLabel(string $raw, string $stem): string
{
	if (preg_match('/^---\s*\R(.*?)\R---\s*\R/s', $raw, $block) === 1
		&& preg_match('/^feature:\s*(.+?)\s*$/m', $block[1], $matches) === 1) {
		$label = trim($matches[1]);
		$label = preg_replace('/^([\'"])(.*)\1$/', '$2', $label);
		if ($label !== '') {
			return str_replace("''", "'", $label);
		}
	}
	return ucfirst(str_replace('-', ' ', $stem));
}

/**
 * Parse markdown documentation into structured TOC and HTML sections.
 *
 * Only "##" and "###" open a section. Anything before the first "##" is dropped, so a lead
 * paragraph written under the "#" title never reaches the page - see docs/configuration.md.
 *
 * @param string $markdown Raw markdown content
 * @param string $idPrefix Prefix applied to every anchor id, to keep several files apart
 * @return array{toc: array, sections: array}
 */
function babyfootParseMarkdown(string $markdown, string $idPrefix = ''): array
{
	$lines = explode("\n", $markdown);
	$toc = [];
	$sections = [];
	$currentSection = null;
	$buffer = [];
	$fence = null;

	foreach ($lines as $line) {
		$trimmed = trim($line);

		// Inside a fence a "#" line is a comment of the quoted code, never a heading.
		if ($fence === null) {
			$fence = babyfootFenceOpen($trimmed);
			$inCode = ($fence !== null);
		} else {
			$inCode = true;
			if (babyfootFenceCloses($trimmed, $fence)) {
				$fence = null;
			}
		}
		if ($inCode) {
			if ($currentSection !== null) {
				$buffer[] = $line;
			}
			continue;
		}

		if (preg_match('/^(#{2,3})\s+(.+)$/', $trimmed, $matches)) {
			if ($currentSection !== null) {
				$currentSection['html'] .= babyfootRenderBuffer($buffer);
				$buffer = [];
				$sections[] = $currentSection;
			}

			$level = strlen($matches[1]);
			$text = $matches[2];
			$id = $idPrefix . babyfootSlugify($text);

			// The sidebar prints plain text, so the backticks of an inline-code heading would
			// show up literally there.
			$toc[] = [
				'level' => $level,
				'text' => str_replace('`', '', $text),
				'id' => $id,
			];

			$tag = ($level === 2) ? 'h2' : 'h3';
			$currentSection = [
				'id' => $id,
				'level' => $level,
				// babyfootInline() escapes before it formats, so this is not a hole.
				'html' => '<div class="aidoc-section" id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"><' . $tag . '>'
					. babyfootInline($text) . '</' . $tag . '>',
			];
			continue;
		}

		// A dropped heading still ends the paragraph above it.
		if (preg_match('/^#{1,6}\s+/', $trimmed)) {
			$line = '';
		}
		if ($currentSection === null) {
			continue;
		}

		$buffer[] = $line;
	}

	if ($currentSection !== null) {
		$currentSection['html'] .= babyfootRenderBuffer($buffer);
		$sections[] = $currentSection;
	}

	foreach ($sections as &$s) {
		$s['html'] .= '</div>';
	}
	unset($s);

	return ['toc' => $toc, 'sections' => $sections];
}

/**
 * Render the documentation layout (sidebar navigation + content sections).
 *
 * @param array $toc       TOC entries {level, text, id}
 * @param array $sections  Section entries {id, level, html}
 * @param bool  $byFeature True when the source is one file per feature
 * @return string HTML
 */
function babyfootRenderDoc(array $toc, array $sections, bool $byFeature = false): string
{
	$html = '<div class="aidoc-wrapper">';

	$html .= '<nav class="aidoc-sidebar" id="aidoc-sidebar">';
	foreach ($toc as $entry) {
		$id = htmlspecialchars($entry['id'], ENT_QUOTES, 'UTF-8');
		// A "##" is a plain group title in per-feature mode only; with configuration.md it must stay a link.
		if ($entry['level'] === 2 && $byFeature) {
			$html .= '<div class="aidoc-nav-title">' . htmlspecialchars($entry['text'], ENT_QUOTES, 'UTF-8') . '</div>';
		} else {
			$html .= '<a href="#' . $id . '" data-section="' . $id . '" class="aidoc-nav-l' . ((int) $entry['level']) . '">'
				. htmlspecialchars($entry['text'], ENT_QUOTES, 'UTF-8') . '</a>';
		}
	}
	$html .= '</nav>';

	$html .= '<div class="aidoc-content' . ($byFeature ? ' aidoc-by-feature' : '')
		. '" id="aidoc-content">';
	foreach ($sections as $section) {
		$html .= $section['html'];
	}
	$html .= '</div>';

	$html .= '</div>';

	return $html;
}

/**
 * Render a buffer of markdown lines into HTML (paragraphs, lists, blockquotes, code fences).
 *
 * Deliberately a subset of markdown, not a parser. What it does NOT support, so that a
 * documentation file is written within these bounds rather than against them:
 *  - tables: each row comes out as its own paragraph of pipes, never as a <table>;
 *  - links: they reach the page as literal text;
 *  - nested lists: the indentation is trimmed, so a sub-item becomes a sibling;
 *  - a fenced block inside a list item: it closes the list, the next item opens a second one;
 *  - emphasis markers inside a code span: `a*b*c` still becomes <code>a<em>b</em>c</code>.
 *
 * @param string[] $lines Lines of markdown
 * @return string HTML
 */
function babyfootRenderBuffer(array $lines): string
{
	$html = '';
	$i = 0;
	$count = count($lines);

	while ($i < $count) {
		$trimmed = trim($lines[$i]);

		if ($trimmed === '') {
			$i++;
			continue;
		}

		// Fenced code block: everything up to the closing fence is emitted verbatim.
		$fence = babyfootFenceOpen($trimmed);
		if ($fence !== null) {
			$i++;
			$code = [];
			while ($i < $count && ! babyfootFenceCloses(trim($lines[$i]), $fence)) {
				$code[] = $lines[$i];
				$i++;
			}
			$i++;
			$html .= '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . '</code></pre>';
			continue;
		}

		// Blockquote (notes / warnings)
		if (preg_match('/^>\s*(.*)$/', $trimmed, $matches)) {
			$noteContent = $matches[1];
			$i++;
			while ($i < $count && preg_match('/^>\s*(.*)$/', trim($lines[$i]), $bqm)) {
				$noteContent .= ' ' . $bqm[1];
				$i++;
			}

			$cssClass = 'aidoc-note';
			if (stripos($noteContent, 'Attention') !== false || stripos($noteContent, 'Warning') !== false) {
				$cssClass .= ' aidoc-note-warning';
			} elseif (stripos($noteContent, 'Note') !== false || stripos($noteContent, 'Info') !== false) {
				$cssClass .= ' aidoc-note-info';
			}

			$html .= '<div class="' . $cssClass . '">' . babyfootInline($noteContent) . '</div>';
			continue;
		}

		// List, ordered or not. A line carrying no marker continues the previous item: markdown
		// soft-wraps, and breaking on it closed the list and reopened a second one after a stray
		// paragraph.
		$first = babyfootListItem($trimmed);
		if ($first !== null) {
			$tag = $first['tag'];
			$start = $first['start'];
			$items = [];
			while ($i < $count) {
				$current = trim($lines[$i]);
				if ($current === '' || babyfootOpensBlock($current)) {
					break;
				}
				$item = babyfootListItem($current);
				if ($item === null) {
					$items[count($items) - 1] .= ' ' . $current;
					$i++;
					continue;
				}
				if ($item['tag'] !== $tag) {
					break;
				}
				$items[] = $item['text'];
				$i++;
			}
			$html .= '<' . $tag . ($start !== 1 ? ' start="' . $start . '"' : '') . '>';
			foreach ($items as $text) {
				$html .= '<li>' . babyfootInline($text) . '</li>';
			}
			$html .= '</' . $tag . '>';
			continue;
		}

		// Paragraph: markdown ends one on a blank line, not on a newline. Source files are
		// hard-wrapped near 100 columns, so taking one line per <p> cuts sentences in half.
		$paragraph = $trimmed;
		$i++;
		// A table row stands alone: folding the line under it into the row read as one long
		// cell, where one paragraph per row at least keeps the columns legible.
		while ($i < $count && strpos($paragraph, '|') !== 0) {
			$next = trim($lines[$i]);
			if ($next === '' || babyfootOpensBlock($next) || babyfootListItem($next) !== null) {
				break;
			}
			$paragraph .= ' ' . $next;
			$i++;
		}
		$html .= '<p>' . babyfootInline($paragraph) . '</p>';
	}

	return $html;
}

/**
 * Tell whether a trimmed line opens a note, a table row or a fence, which end a list or a paragraph.
 *
 * @param string $line Trimmed line
 * @return bool
 */
function babyfootOpensBlock(string $line): bool
{
	return preg_match('/^[>|]/', $line) === 1 || babyfootFenceOpen($line) !== null;
}

/**
 * Read a fence opener off a trimmed line.
 *
 * A backtick fence carries no backtick after its run: "```x``` inline" is a code span.
 *
 * @param string $line Trimmed line
 * @return string|null The run of fence characters, null when the line opens no fence
 */
function babyfootFenceOpen(string $line): ?string
{
	if (preg_match('/^(?:(`{3,})[^`]*|(~{3,}).*)$/', $line, $matches) !== 1) {
		return null;
	}
	return $matches[1] !== '' ? $matches[1] : $matches[2];
}

/**
 * Tell whether a trimmed line closes the fence opened by $fence.
 *
 * @param string $line  Trimmed line
 * @param string $fence Run returned by babyfootFenceOpen()
 * @return bool True on a bare run of the same character, at least as long
 */
function babyfootFenceCloses(string $line, string $fence): bool
{
	return preg_match('/^' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}$/', $line) === 1;
}

/**
 * Read a list item marker off a trimmed line.
 *
 * An ordered marker is two digits at most: "2026. Une annee" opens a sentence far more often
 * than a hundred-item list, and turning it into an <li> would swallow the number.
 *
 * @param string $line Trimmed line
 * @return array{tag: string, text: string, start: int}|null Null when the line opens no list
 *                                                           item; start is 1 for a bullet
 */
function babyfootListItem(string $line): ?array
{
	if (preg_match('/^(?:([-*])|([0-9]{1,2})\.)\s+(.+)$/', $line, $matches) !== 1) {
		return null;
	}
	if ($matches[1] !== '') {
		return ['tag' => 'ul', 'text' => $matches[3], 'start' => 1];
	}
	return ['tag' => 'ol', 'text' => $matches[3], 'start' => (int) $matches[2]];
}

/**
 * Format inline markdown: bold, italic, code.
 *
 * Escaping happens first, so the markdown substitutions below can only introduce the tags
 * written here - never a tag coming from the source file.
 *
 * @param string $text Raw text
 * @return string HTML
 */
function babyfootInline(string $text): string
{
	$text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	$text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
	$text = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text);
	$text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
	$text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
	return $text;
}

/**
 * Generate a URL-safe slug from header text.
 *
 * @param string $text Header text
 * @return string Slug, restricted to [a-z0-9-]
 */
function babyfootSlugify(string $text): string
{
	$text = mb_strtolower($text, 'UTF-8');
	$text = str_replace(
		['à', 'â', 'ä', 'é', 'è', 'ê', 'ë', 'î', 'ï', 'ô', 'ö', 'ù', 'û', 'ü', 'ç', 'ñ'],
		['a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'o', 'o', 'u', 'u', 'u', 'c', 'n'],
		$text
	);
	$text = str_replace(["'", "\xe2\x80\x99", "\xe2\x80\x98"], '', $text);
	$text = preg_replace('/[^a-z0-9]+/', '-', $text);
	return trim($text, '-');
}
