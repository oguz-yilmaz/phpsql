<?php
/**
 * Copyright (C) 2013  Gerrit Addiks.
 * This package (including this file) was released under the terms of the GPL-3.0.
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <http://www.gnu.org/licenses/> or send me a mail so i can send you a copy.
 * @license GPL-3.0
 * @author Gerrit Addiks <gerrit@addiks.de>
 * @package Addiks
 */

namespace Addiks\PHPSQL\Filesystem;

use Addiks\PHPSQL\Filesystem;
use Addiks\PHPSQL\Value\Text\Filepath;

interface FilesystemInterface
{
    
    public function getFileContents(Filepath $filePath);
    
    public function putFileContents(Filepath $filePath, $content, $flags = 0);
    
    public function fileOpen(Filepath $filePath, $mode);
    
    public function fileClose($handle);
    
    public function fileWrite($handle, $data);
    
    public function fileRead($handle, $length);
    
    public function fileTruncate($handle, $size);
    
    public function fileSeek($handle, $offset, $seekMode = SEEK_SET);
    
    public function fileTell($handle);
    
    public function fileEOF($handle);
    
    public function fileReadLine($handle);
    
    public function fileUnlink($filePath);

    /**
     * removes recursive a whole directory
     * (copied from a comment in http://de.php.net/rmdir)
     *
     * @package Addiks
     * @subpackage External
     * @author Someone else from the thing called internet (NOSPAMzentralplan dot de)
     * @param string $dir
     */
    public static function rrmdir($dir);
}
