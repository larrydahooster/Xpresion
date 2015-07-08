<?php


namespace Xpresion;



class XpresionOp extends XpresionTok
{
    public static function Condition($f)
    {
        return array(
            is_callable($f[0]) ? $f[0] : XpresionTpl::compile(XpresionTpl::multisplit($f[0],array('${POS}'=>0,'${TOKS}'=>1,'${OPS}'=>2,'${TOK}'=>3,'${OP}'=>4,'${PREV_IS_OP}'=>5,'${DEDUCED_TYPE}'=>6 /*,'${XPRESION}'=>7*/)), true)
        ,$f[1]
        );
    }

    public static function parse_definition( $op_def )
    {
        $parts = array();
        $op = array();
        $arity = 0;
        $arity_min = 0;
        $arity_max = 0;
        if ( is_string($op_def) )
        {
            // assume infix, arity = 2;
            $op_def = array(1,$op_def,1);
        }
        else
        {
            $op_def = (array)$op_def;
        }
        $l = count($op_def);
        for ($i=0; $i<$l; $i++)
        {
            if ( is_string( $op_def[$i] ) )
            {
                $parts[] = $op_def[$i];
                $op[] = array(1, $i, $op_def[$i]);
            }
            else
            {
                $op[] = array(0, $i, $op_def[$i]);
                $num_args = abs($op_def[$i]);
                $arity += $num_args;
                $arity_max += $num_args;
                $arity_min += $op_def[$i];
            }
        }
        if ( 1 === count($parts) && 1 === count($op) )
        {
            $op = array(array(0, 0, 1), array(1, 1, $parts[0]), array(0, 2, 1));
            $arity_min = $arity_max = $arity = 2; $type = Xpresion::T_OP;
        }
        else
        {
            $type = count($parts) > 1 ? Xpresion::T_N_OP : Xpresion::T_OP;
        }
        return array($type, $op, $parts, $arity, max(0, $arity_min), $arity_max);
    }

    public static function match_args( $expected_args, $args_pos, &$op_queue, &$token_queue )
    {
        $tl = count($token_queue);
        $t = $tl-1;
        $num_args = 0;
        $num_expected_args = abs($expected_args);
        $INF = -10;
        while ($num_args < $num_expected_args || $t >= 0 )
        {
            $p2 = $t >= 0 ? $token_queue[$t]->pos : $INF;
            if ( $args_pos === $p2 )
            {
                $num_args++;
                $args_pos--;
                $t--;
            }
            else break;
        }
        return $num_args >= $num_expected_args ? $num_expected_args : ($expected_args <= 0 ? 0 : false);
    }

    public $otype = null;
    public $ofixity = null;
    public $opdef = null;
    public $parts = null;
    public $morphes = null;

    public function __construct($input='', $fixity=null, $associativity=null, $priority=1000, /*$arity=0,*/ $output='', $otype=null, $ofixity=null)
    {
        $opdef = self::parse_definition( $input );
        $this->type = $opdef[0];
        $this->opdef = $opdef[1];
        $this->parts = $opdef[2];

        if ( !($output instanceof XpresionTpl) ) $output = new XpresionTpl($output);

        parent::__construct($this->type, $this->parts[0], $output);

        $this->fixity = null !== $fixity ? $fixity : Xpresion::PREFIX;
        $this->associativity = null !== $associativity ? $associativity : Xpresion::DEFAUL;
        $this->priority = $priority;
        $this->arity = $opdef[3];
        $this->arity_min = $opdef[4];
        $this->arity_max = $opdef[5];
        //$this->arity = $arity;
        $this->otype = null !== $otype ? $otype : Xpresion::T_DFT;
        $this->ofixity = null !== $ofixity ? $ofixity : $this->fixity;
        $this->parenthesize = false;
        $this->revert = false;
        $this->morphes = null;
    }

    public function __destruct()
    {
        $this->dispose();
    }

    public function dispose()
    {
        parent::dispose();
        $this->otype = null;
        $this->ofixity = null;
        $this->opdef = null;
        $this->parts = null;
        $this->morphes = null;
        return $this;
    }

    public function Polymorphic($morphes=null)
    {
        if (null===$morphes) $morphes = array();
        $this->type = Xpresion::T_POLY_OP;
        $this->morphes = array_map(array(__NAMESPACE__ . '\XpresionOp','Condition'), (array)$morphes);
        return $this;
    }

    public function morph($args)
    {
        $morphes = $this->morphes;
        $l = count($morphes);
        $i = 0;
        $minop = $morphes[0][1];
        $found = false;

        if (count($args) < 7)
        {
            $args[] = count($args[1]) ? $args[1][count($args[1])-1] : false;
            $args[] = count($args[2]) ? $args[2][0] : false;
            $args[] = $args[4] ? ($args[4]->pos+1===$args[0]) : false;
            $args[] = $args[4] ? $args[4]->type : ($args[3] ? $args[3]->type : 0);
            //$args[] = Xpresion;
        }

        while ($i < $l)
        {
            $op = $morphes[$i++];
            if (true === (bool)$op[0]( $args ))
            {
                $op = $op[1];
                $found = true;
                break;
            }
            if ($op[1]->priority >= $minop->priority) $minop = $op[1];
        }

        # try to return minimum priority operator, if none matched
        if (!$found) $op = $minop;
        # nested polymorphic op, if any
        while (Xpresion::T_POLY_OP === $op->type) $op = $op->morph( $args );
        return $op;
    }

    public function render($args=null)
    {
        $output_type = $this->otype;
        $op = $this->output;
        $p = $this->parenthesize;
        $lparen = $p ? Xpresion::LPAREN : '';
        $rparen = $p ? Xpresion::RPAREN : '';
        $comma = Xpresion::COMMA;
        $out_fixity = $this->ofixity;
        if (!$args || empty($args)) $args=array('','');
        $numargs = count($args);

        //if (T_DUM == output_type) and numargs:
        //    output_type = args[ 0 ].type

        //args = list(map(Tok.render, args))

        if ($op instanceof XpresionTpl)
            $out = $lparen . $op->render( $args ) . $rparen;
        elseif (Xpresion::INFIX === $out_fixity)
            $out = $lparen . implode(strval($op), $args) . $rparen;
        elseif (Xpresion::POSTFIX === $out_fixity)
            $out = $lparen . implode($comma, $args) . $rparen . strval($op);
        else // if (Xpresion::PREFIX === $out_fixity)
            $out = strval($op) . $lparen . implode($comma, $args) . $rparen;
        return new XpresionTok($output_type, $out, $out);
    }

    public function validate($pos, &$op_queue, &$token_queue)
    {
        $msg = ''; $num_args = 0;
        if ( 0 === $this->opdef[0][0] ) // expecting argument(s)
        {
            $num_args = self::match_args($this->opdef[0][2], $pos-1, $op_queue, $token_queue );
            if ( false === $num_args )
            {
                $msg = 'Operator "' . $this->input . '" expecting ' . $this->opdef[0][2] . ' prior argument(s)';
            }
        }
        return array($num_args, $msg);
    }

    public function node($args=null, $pos=0, $op_queue=null, $token_queue=null)
    {
        $otype = $this->otype;
        if (null===$args) $args = array();
        if ($this->revert) $args = array_reverse($args);
        if ((Xpresion::T_DUM === $otype) && !empty($args)) $otype = $args[ 0 ]->type;
        elseif (!empty($args)) $args[0]->type = $otype;
        $n = new XpresionNode($otype, $this->arity, $this, $args, $pos);
        if (Xpresion::T_N_OP === $this->type && null !== $op_queue)
        {
            $n->op_parts = array_slice($this->parts, 1);
            $n->op_def = array_slice($this->opdef, 0 === $this->opdef[0][0] ? 2 : 1);
            $n->op_index = count($op_queue)+1;
        }
        return $n;
    }
}