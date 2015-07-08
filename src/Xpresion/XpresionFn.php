<?php


namespace Xpresion;


class XpresionFn
{
    public $Fn = array();

    public $INF = INF;
    public $NAN = NAN;

    /*public function __construct()
    {
        $this->Fn = array();
    }*/

    public static function __set_state($an_array) // As of PHP 5.1.0
    {
        $obj = new XpresionFn();
        $obj->INF = INF;
        $obj->NAN = NAN;
        $obj->Fn = (array)$an_array['Fn'];
        return $obj;
    }

    public function __call($fn, $args)
    {
        if ( $fn && isset($this->Fn[$fn]) && is_callable($this->Fn[$fn]) )
        {
            return call_user_func_array($this->Fn[$fn], (array)$args);
        }
        throw new \Exception('Unknown Runtime Function "'.$fn.'"');
    }

    # function implementations (can also be overriden per instance/evaluation call)
    public function clamp($v, $m, $M)
    {
        if ($m > $M) return ($v > $m ? $m : ($v < $M ? $M : $v));
        else return ($v > $M ? $M : ($v < $m ? $m : $v));
    }

    public function len($v)
    {
        if ($v)
        {
            if (is_string($v)) return strlen($v);
            elseif (is_array($v)) return count($v);
            elseif (is_object($v)) return count((array)$v);
            return 1;
        }
        return 0;
    }

    public function sum(/* args */)
    {
        $args = func_get_args();
        $s = 0;
        $values = $args;
        if (!empty($values) && is_array($values[0])) $values = $values[0];
        foreach ($values as $v) $s += $v;
        return $s;
    }

    public function avg(/* args */)
    {
        $args = func_get_args();
        $s = 0;
        $values = $args;
        if (!empty($values) && is_array($values[0])) $values = $values[0];
        $l = count($values);
        foreach ($values as $v) $s += $v;
        return $l > 0 ? $s/$l : $s;
    }

    public function ary_merge($a1, $a2)
    {
        return array_merge((array)$a1, (array)$a2);
    }

    public function ary_eq($a1, $a2)
    {
        return (bool)(((array)$a1) == ((array)$a2));
    }

    public function match($str, $regex)
    {
        return (bool)preg_match($regex, $str, $m);
    }

    public function contains($o, $i)
    {
        if ( is_string($o) ) return (false !== strpos($o, strval($i)));
        elseif ( XpresionUtils::is_assoc_array($o) ) return array_key_exists($i, $o);
        return in_array($i, $o);
    }
}