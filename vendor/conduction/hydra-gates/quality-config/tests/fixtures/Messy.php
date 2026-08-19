<?php
namespace OCA\Fixture\Service;
class Messy
{
    public function go( $a,$b )
    {
        $x = (int) $a;
        $y = 'a'.'b';
        if ($a == 1) { return $x; }
        else if ($b) { return $y; }
        return null;
    }
}
