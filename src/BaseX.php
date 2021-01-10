<?php
/**
 * User: Lessmore92
 * Date: 1/7/2021
 * Time: 12:58 PM
 */

namespace Lessmore92\BaseX;

use Exception;

class BaseX
{
    private $alphabet;
    private $baseMap;
    private $base;
    private $leader;
    private $factor;
    private $iFactor;

    public function __construct($alphabet)
    {
        $alphabet = str_split($alphabet);
        if (sizeof($alphabet) >= 255)
        {
            throw new Exception('Alphabet too long');
        }
        $this->alphabet = $alphabet;

        $this->baseMap = array_fill(0, 254, 255);


        foreach ($alphabet as $i => $char)
        {
            if ($this->baseMap[ord($char)] !== 255)
            {
                throw new Exception($char . ' is ambiguous');
            }
            $this->baseMap[ord($char)] = $i;
        }

        $this->base    = sizeof($alphabet);
        $this->leader  = $alphabet[0];
        $this->factor  = log($this->base) / log(256);
        $this->iFactor = log(256) / log($this->base);
    }

    public function encode(Buffer $bytes)
    {
        $zeroes = 0;
        $length = 0;
        $pbegin = 0;
        $pend   = $bytes->getSize();
        $_bytes = $bytes->getDecimal();
        while ($pbegin !== $pend && $_bytes[$pbegin] == 0)
        {
            $pbegin++;
            $zeroes++;
        }

        $size = $this->unsignedRightShift(($pend - $pbegin) * $this->iFactor + 1, 0);
        $b58  = array_fill(0, $size, 0);

        while ($pbegin !== $pend)
        {
            $carry = $_bytes[$pbegin];
            $i     = 0;
            for ($it1 = $size - 1; ($carry !== 0 || $i < $length) && ($it1 !== -1); $it1--, $i++)
            {
                $carry     += $this->unsignedRightShift(256 * $b58[$it1], 0);
                $b58[$it1] = $this->unsignedRightShift($carry % $this->base, 0);
                $carry     = $this->unsignedRightShift($carry / $this->base, 0);
            }
            if ($carry !== 0)
            {
                throw new Exception('Non-zero carry');
            }
            $length = $i;
            $pbegin++;
        }

        $it2 = $size - $length;
        while ($it2 !== $size && $b58[$it2] === 0)
        {
            $it2++;
        }

        $str = str_repeat($this->leader, $zeroes);
        for (; $it2 < $size; ++$it2)
        {
            $str .= $this->alphabet[$b58[$it2]];
        }
        return $str;
    }

    private function unsignedRightShift($a, $b)
    {
        if ($b >= 32 || $b < -32)
        {
            $m = (int)($b / 32);
            $b = $b - ($m * 32);
        }

        if ($b < 0)
        {
            $b = 32 + $b;
        }

        if ($b == 0)
        {
            return (($a >> 1) & 0x7fffffff) * 2 + (($a >> $b) & 1);
        }

        if ($a < 0)
        {
            $a = ($a >> 1);
            $a &= 0x7fffffff;
            $a |= 0x40000000;
            $a = ($a >> ($b - 1));
        }
        else
        {
            $a = ($a >> $b);
        }
        return $a;
    }

    public function decode(string $string)
    {
        $buffer = $this->decodeUnsafe($string);
        if ($buffer)
        {
            return $buffer;
        }
        throw new Exception(sprintf("Non-base%s character", $this->base));
    }

    public function decodeUnsafe(string $source)
    {
        if (strlen($source) == 0)
        {
            return new Buffer();
        }

        $psz = 0;
        if ($source[$psz] === ' ')
        {
            return;
        }

        $zeroes = 0;
        $length = 0;


        while ($source[$psz] === $this->leader)
        {
            $zeroes++;
            $psz++;
        }

        $size = $this->unsignedRightShift(((strlen($source) - $psz) * $this->factor) + 1, 0); // log(58) / log(256), rounded up.
        $b256 = array_fill(0, $size, 0);

        while (isset($source[$psz]))
        {
            // Decode character
            $carry = $this->baseMap[ord($source[$psz])];

            // Invalid character
            if ($carry === 255)
            {
                return;
            }
            $i = 0;
            for ($it3 = $size - 1; ($carry !== 0 || $i < $length) && ($it3 !== -1); $it3--, $i++)
            {
                $carry      += $this->unsignedRightShift($this->base * $b256[$it3], 0);
                $b256[$it3] = $this->unsignedRightShift($carry % 256, 0);
                $carry      = $this->unsignedRightShift($carry / 256, 0);
            }
            if ($carry !== 0)
            {
                throw new Exception('Non-zero carry');
            }
            $length = $i;
            $psz++;
        }

        if (isset($source[$psz]) && $source[$psz] === ' ')
        {
            return;
        }
        $it4 = $size - $length;
        while ($it4 !== $size && $b256[$it4] === 0)
        {
            $it4++;
        }

        $vch = Buffer::hex(str_repeat('00', $zeroes + ($size - $it4)));
        $vch = $vch->getDecimal();
        $j   = $zeroes;

        while ($it4 !== $size)
        {
            $vch[$j++] = $b256[$it4++];
        }

        return Buffer::hex($this->decimalArrayToHexStr($vch));
    }

    private function decimalArrayToHexStr(array $decimal)
    {
        return join(array_map(function ($item) {
            return sprintf('%02X', $item);
        }, $decimal));
    }
}
