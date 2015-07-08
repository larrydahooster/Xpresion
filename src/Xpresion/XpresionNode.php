<?php


namespace Xpresion;


class XpresionNode
{
    # depth-first traversal
    public static function DFT($root, $action=null, $andDispose=False)
    {
        /*
            one can also implement a symbolic solver here
            by manipulating the tree to produce 'x' on one side
            and the reverse operators/tokens on the other side
            i.e by transposing the top op on other side of the '=' op and using the 'associated inverse operator'
            in stack order (i.e most top op is transposed first etc.. until only the branch with 'x' stays on one side)
            (easy when only one unknown in one state, more difficult for many unknowns
            or one unknown in different states, e.g x and x^2 etc..)
        */
        $andDispose = (false !== $andDispose);
        if (!$action) $action = array('Xpresion','render');
        $stack = array( $root );
        $output = array( );

        while (!empty($stack))
        {
            $node = $stack[ 0 ];
            if ($node->children && !empty($node->children))
            {
                $stack = array_merge($node->children, $stack);
                $node->children = null;
            }
            else
            {
                array_shift($stack);
                $op = $node->node;
                $arity = $op->arity;
                if ( (Xpresion::T_OP & $op->type) && 0 === $arity ) $arity = 1; // have already padded with empty token
                elseif ( $arity > count($output) && $op->arity_min <= $op->arity ) $arity = $op->arity_min;
                $o = call_user_func($action, $op, (array)array_splice($output, -$arity, $arity));
                $output[] = $o;
                if ($andDispose) $node->dispose( );
            }
        }

        $stack = null;
        return $output[ 0 ];
    }

    public $type = null;
    public $arity = null;
    public $pos = null;
    public $node = null;
    public $op_parts = null;
    public $op_def = null;
    public $op_index = null;
    public $children = null;

    public function __construct($type, $arity, $node, $children=null, $pos=0)
    {
        $this->type = $type;
        $this->arity = $arity;
        $this->node = $node;
        $this->children = $children;
        $this->pos = $pos;
        $this->op_parts = null;
        $this->op_def = null;
        $this->op_index = null;
    }

    public function __destruct()
    {
        $this->dispose();
    }

    public function dispose()
    {
        $c = $this->children;
        if ($c && !empty($c))
        {
            foreach ($c as $ci)
                if ($ci) $ci->dispose( );
        }

        $this->type = null;
        $this->arity = null;
        $this->pos = null;
        $this->node = null;
        $this->op_parts = null;
        $this->op_def = null;
        $this->op_index = null;
        $this->children = null;
        return $this;
    }

    public function op_next($op, $pos, &$op_queue, &$token_queue)
    {
        $num_args = 0;
        $next_index = array_search($op->input, $this->op_parts);
        $is_next = (bool)(0 === $next_index);
        if ( $is_next )
        {
            if ( 0 === $this->op_def[0][0] )
            {
                $num_args = XpresionOp::match_args($this->op_def[0][2], $pos-1, $op_queue, $token_queue );
                if ( false === $num_args )
                {
                    $is_next = false;
                }
                else
                {
                    $this->arity = $num_args;
                    array_shift($this->op_def);
                }
            }
        }
        if ( $is_next )
        {
            array_shift($this->op_def);
            array_shift($this->op_parts);
        }
        return $is_next;
    }

    public function op_complete()
    {
        return (bool)empty($this->op_parts);
    }

    public function __toString(/*$tab=""*/)
    {
        $out = array();
        $n = $this->node;
        $c = !empty($this->children) ? $this->children : array();
        $tab = "";
        $tab_tab = $tab."  ";

        foreach ($c as $ci) $out[] = $ci->__toString(/*$tab_tab*/);
        if (isset($n->parts) && $n->parts) $parts = implode(" ", $n->parts);
        else $parts = $n->input;
        return $tab . implode("\n".$tab, array(
            "Node(".strval($n->type)."): " . $parts,
            "Childs: [",
            $tab . implode("\n".$tab, $out),
            "]"
        )) . "\n";
    }
}