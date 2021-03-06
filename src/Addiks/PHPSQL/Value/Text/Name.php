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

namespace Addiks\PHPSQL\Value\Text;

class Name extends Line
{
    
    protected static function filter($value)
    {
        return trim(parent::filter($value));
    }
    
    protected function validate($value)
    {
        
        parent::validate($value);
        
        if (!preg_match("/[a-zA-Z0-9\-]/is", $value)) {
            throw new  Exception\InvalidValue("A name can only contain letters, numbers and the characters '-' and '_'!");
        }
    }
}
