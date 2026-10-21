<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

/**
 * The readme against the code it describes.
 *
 * Documentation drifts silently: a key added to the config file stays undocumented, a section
 * renamed leaves a dead link in the table of contents, and the translation lags behind the
 * original. All three are cheap to check.
 */
class DocumentationTest extends DatabaseTestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__);
    }

    /**
     * @param string $name
     *
     * @return string
     */
    private function read(string $name): string
    {
        $path = $this->root . '/' . $name;
        $this->assertFileExists($path);

        return (string)file_get_contents($path);
    }

    /**
     * The anchor GitHub builds out of a heading
     *
     * @param string $heading
     *
     * @return string
     */
    private function anchor(string $heading): string
    {
        $anchor = mb_strtolower(trim($heading));
        // punctuation is dropped, spaces become hyphens - letters of any alphabet stay
        $anchor = preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $anchor);

        return (string)preg_replace('/\s+/u', '-', (string)$anchor);
    }

    /**
     * @param string $markdown
     *
     * @return array
     */
    private function headings(string $markdown): array
    {
        preg_match_all('/^##\s+(.+)$/mu', $markdown, $matches);

        return $matches[1];
    }

    public function testEveryEnvironmentVariableOfTheConfigIsDocumented(): void
    {
        preg_match_all("/env\('(MANTICORE_\w+)'/", $this->read('docs/laravel-driver.md'), $matches);
        $variables = array_unique($matches[1]);

        $this->assertNotEmpty($variables);

        foreach (['README.md', 'README.ru.md'] as $readme) {
            $text = $this->read($readme);

            foreach ($variables as $variable) {
                $this->assertStringContainsString(
                    $variable,
                    $text,
                    sprintf('%s does not document "%s" of the config file.', $readme, $variable)
                );
            }
        }
    }

    /**
     * Both files list their own sections, so a renamed heading has to be renamed twice
     */
    public function testTheTableOfContentsMatchesTheSections(): void
    {
        foreach (['README.md', 'README.ru.md'] as $readme) {
            $text = $this->read($readme);

            preg_match_all('/^\*\s+\[([^\]]+)\]\(#([^)]+)\)$/mu', $text, $matches);
            $this->assertNotEmpty($matches[2], $readme . ' has no table of contents');

            $anchors = array_map([$this, 'anchor'], $this->headings($text));

            foreach ($matches[2] as $i => $anchor) {
                $this->assertContains(
                    $anchor,
                    $anchors,
                    sprintf('"%s" of the contents of %s points at no section.', $matches[1][$i], $readme)
                );
            }

            // ... and the other way round, so that a new section is added to the contents
            foreach ($anchors as $section) {
                if ($section !== $this->anchor('Contents') && $section !== $this->anchor('Содержание')) {
                    $this->assertContains(
                        $section,
                        $matches[2],
                        sprintf('Section "%s" of %s is missing from the contents.', $section, $readme)
                    );
                }
            }
        }
    }

    public function testTheTranslationHasTheSameSections(): void
    {
        $english = $this->headings($this->read('README.md'));
        $russian = $this->headings($this->read('README.ru.md'));

        $this->assertSameSize($english, $russian, 'The translation of the readme has drifted apart from the original.');
    }
}
