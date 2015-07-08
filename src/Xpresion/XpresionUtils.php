<?php


namespace Xpresion;


class XpresionUtils
{
    #def trace( stack ):
    #    out = []
    #    for i in stack: out.append(i.__str__())
    #    return (",\n").join(out)

    public static $dummy = null;

    public static function parse_re_flags($s,$i,$l)
    {
        $flags = '';
        $has_i = false;
        $has_g = false;
        $seq = 0;
        $i2 = $i+$seq;
        $not_done = true;
        while ($i2 < $l && $not_done)
        {
            $ch = $s[$i2++];
            $seq += 1;
            if ('i' == $ch && !$has_i)
            {
                $flags .= 'i';
                $has_i = true;
            }

            if ('g' == $ch && !$has_g)
            {
                $flags .= 'g';
                $has_g = true;
            }

            if ($seq >= 2 || (!$has_i && !$has_g))
            {
                $not_done = false;
            }
        }
        return $flags;
    }

    public static function is_assoc_array( $a )
    {
        // http://stackoverflow.com/a/265144/3591273
        $k = array_keys( $a );
        return (bool)($k !== array_keys( $k ));
    }

    public static function evaluator_factory( $evaluator_str, $Fn, $Cache )
    {
        if ( version_compare(phpversion(), '5.3.0', '>=') )
        {
            // use actual php anonynous function/closure
            $evaluator_factory = create_function('$Fn,$Cache', implode("\n", array(
                '$evaluator = function($Var) use($Fn,$Cache) {',
                '    return ' . $evaluator_str . ';',
                '};',
                'return $evaluator;'
            )));
            $evaluator = $evaluator_factory($Fn,$Cache);
        }
        else
        {
            // simulate closure variables in PHP < 5.3
            // with create_function and var_export
            $evaluator = create_function('$Var', implode("\n", array(
                '$Cache = (object)' . var_export((array)$Cache, true) . ';',
                '$Fn = ' . var_export($Fn, true) . ';',
                'return ' . $evaluator_str . ';'
            )));
        }
        return $evaluator;
    }
}