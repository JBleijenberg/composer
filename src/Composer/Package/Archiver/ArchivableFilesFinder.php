<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Package\Archiver;

use Composer\Util\Filesystem;
use FilesystemIterator;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * A Symfony Finder wrapper which locates files that should go into archives
 *
 * Handles .gitignore, .gitattributes and .hgignore files as well as composer's
 * own exclude rules from composer.json
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class ArchivableFilesFinder extends \FilterIterator
{
    /**
     * @var Finder
     */
    protected $finder;

    /**
     * Initializes the internal Symfony Finder with appropriate filters
     *
     * @param string $sources  Path to source files to be archived
     * @param array  $excludes Composer's own exclude rules from composer.json
     */
    public function __construct($sources, array $excludes)
    {
        $fs = new Filesystem();

        $sources = $fs->normalizePath($sources);

        $filters = array(
            new HgExcludeFilter($sources),
            new GitExcludeFilter($sources),
            new ComposerExcludeFilter($sources, $excludes),
        );

        $this->finder = new Finder();

        $filter = function (\SplFileInfo $file) use ($sources, $filters, $fs) {
            if ($file->isLink() && strpos($file->getLinkTarget(), $sources) !== 0) {
                return false;
            }

            /*
            * Check if the current \SplFileInfo instance is a directory.
            * If it is and the directory is empty, add a .gitkeep file.
            *
            * This is mainly done because Composer doesn't add empty directories to it's archives.
            * Adding a .gitkeep files makes sure that Composer doesn't forget about this directory.
            */
            if ($file->isDir()) {
                $directoryContents = scandir($file->getPathname());

                if (count($directoryContents) <= 2) {
                    file_put_contents($file->getPathname() . DIRECTORY_SEPARATOR . '.gitkeep', '');
                }
            }

            $relativePath = preg_replace(
                '#^'.preg_quote($sources, '#').'#',
                '',
                $fs->normalizePath($file->getRealPath())
            );

            $exclude = false;
            foreach ($filters as $filter) {
                $exclude = $filter->filter($relativePath, $exclude);
            }

            return !$exclude;
        };

        if (method_exists($filter, 'bindTo')) {
            $filter = $filter->bindTo(null);
        }

        $this->finder
            ->in($sources)
            ->filter($filter)
            ->ignoreVCS(true)
            ->ignoreDotFiles(false);

        parent::__construct($this->finder->getIterator());
    }

    public function accept()
    {
        /** @var SplFileInfo $current */
        $current = $this->getInnerIterator()->current();

        if (!$current->isDir()) {
            return true;
        }

        $iterator = new FilesystemIterator($current, FilesystemIterator::SKIP_DOTS);

        return !$iterator->valid();
    }
}
