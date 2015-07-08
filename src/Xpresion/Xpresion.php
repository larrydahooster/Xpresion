<?php

namespace Xpresion;

class Xpresion
{
    const VERSION = "0.6.1";

    const COMMA       =   ',';
    const LPAREN      =   '(';
    const RPAREN      =   ')';

    const NONE        =   0;
    const DEFAUL      =   1;
    const LEFT        =  -2;
    const RIGHT       =   2;
    const PREFIX      =   2;
    const INFIX       =   4;
    const POSTFIX     =   8;

    const T_DUM       =   0;
    const T_DFT       =   1;
    const T_IDE       =   16;
    const T_VAR       =   17;
    const T_LIT       =   32;
    const T_NUM       =   33;
    const T_STR       =   34;
    const T_REX       =   35;
    const T_BOL       =   36;
    const T_DTM       =   37;
    const T_ARY       =   38;
    const T_OP        =   128;
    const T_N_OP      =   129;
    const T_POLY_OP   =   130;
    const T_FUN       =   131;
    const T_EMPTY     =   1024;

    public static $_inited = false;
    public static $_configured = false;

    public static $OPERATORS_S = null;
    public static $FUNCTIONS_S = null;
    public static $BLOCKS_S = null;
    public static $RE_S = null;
    public static $Reserved_S = null;
    public static $Fn_S = null;
    public static $EMPTY_TOKEN = null;

    public static function Tpl($tpl='', $replacements=null, $compiled=false)
    {
        return new XpresionTpl($tpl, $replacements, $compiled);
    }
    public static function Node($type, $node, $children=null, $pos=0)
    {
        return new XpresionNode($type, $node, $children, $pos);
    }
    public static function Alias($alias)
    {
        return new XpresionAlias($alias);
    }
    public static function Tok($type, $input, $output, $value=null)
    {
        return new XpresionTok($type, $input, $output, $value);
    }
    public static function Op($input='', $fixity=null, $associativity=null, $priority=1000, /*$arity=0,*/ $output='', $otype=null, $ofixity=null)
    {
        return new XpresionOp($input, $fixity, $associativity, $priority, /*$arity,*/ $output, $otype, $ofixity);
    }
    public static function Func($input='', $output='', $otype=null, $priority=1, $arity=1, $associativity=null, $fixity=null)
    {
        return new XpresionFunc($input, $output, $otype, $priority, $arity, $associativity, $fixity);
    }

    public static function reduce(&$token_queue, &$op_queue, &$nop_queue, $current_op=null, $pos=0, $err=null)
    {
        $nop = null;
        $nop_index = 0;
        /*
            n-ary operatots (eg ternary) or composite operators
            as operators with multi-parts
            which use their own stack or equivalently
            lock their place on the OP_STACK
            until all the parts of the operator are
            unified and collapsed

            Equivalently n-ary ops are like ops which relate NOT to
            args but to other ops

            In this way the BRA_KET special op handling
            can be made into an n-ary op with uniform handling
        */
        // TODO: maybe do some optimisation here when 2 operators can be combined into 1, etc..
        // e.g not is => isnot

        if ($current_op)
        {
            $opc = $current_op;

            // polymorphic operator
            // get the current operator morph, based on current context
            if (Xpresion::T_POLY_OP === $opc->type)
                $opc = $opc->morph(array($pos,$token_queue,$op_queue));

            // n-ary/multi-part operator, initial part
            // push to nop_queue/op_queue
            if (Xpresion::T_N_OP === $opc->type)
            {
                $validation = $opc->validate($pos, $op_queue, $token_queue);
                if ( false === $validation[0] )
                {
                    // operator is not valid in current state
                    $err->err = true;
                    $err->msg = $validation[1];
                    return false;
                }
                $n = $opc->node(null, $pos, $op_queue, $token_queue);
                $n->arity = $validation[0];
                array_unshift($nop_queue, $n);
                array_unshift($op_queue, $n);
            }
            else
            {
                if (!empty($nop_queue))
                {
                    $nop = $nop_queue[0];
                    $nop_index = $nop->op_index;
                }

                // n-ary/multi-part operator, further parts
                // combine one-by-one, until n-ary operator is complete
                if ($nop && $nop->op_next( $opc, $pos, $op_queue, $token_queue ))
                {
                    while (count($op_queue) > $nop_index)
                    {
                        $entry = array_shift($op_queue);
                        $op = $entry->node;
                        $arity = $op->arity;
                        if ( (Xpresion::T_OP & $op->type) && 0 === $arity ) $arity = 1; // have already padded with empty token
                        elseif ( $arity > count($token_queue) && $op->arity_min <= $op->arity ) $arity = $op->arity_min;
                        $n = $op->node(array_splice($token_queue, -$arity, $arity), $entry->pos);
                        array_push($token_queue, $n);
                    }


                    if ($nop->op_complete( ))
                    {
                        array_shift($nop_queue);
                        array_shift($op_queue);
                        $opc = $nop->node;
                        $nop->dispose( );
                        $nop_index = !empty($nop_queue) ? $nop_queue[0]->op_index : 0;
                    }
                    else
                    {
                        return;
                    }
                }
                else
                {
                    $validation = $opc->validate($pos, $op_queue, $token_queue);
                    if ( false === $validation[0] )
                    {
                        // operator is not valid in current state
                        $err->err = true;
                        $err->msg = $validation[1];
                        return false;
                    }
                }

                $fixity = $opc->fixity;

                if (Xpresion::POSTFIX === $fixity)
                {
                    // postfix assumed to be already in correct order,
                    // no re-structuring needed
                    $arity = $opc->arity;
                    if ( $arity > count($token_queue) && $opc->arity_min <= count($token_queue) ) $arity = $opc->arity_min;
                    $n = $opc->node(array_splice($token_queue, -$arity, $arity), $pos);
                    array_push($token_queue, $n);
                }

                elseif (Xpresion::PREFIX === $fixity)
                {
                    // prefix assumed to be already in reverse correct order,
                    // just push to op queue for later re-ordering
                    array_unshift($op_queue, new XpresionNode($opc->otype, $opc->arity, $opc, null, $pos));
                    if ( (/*T_FUN*/Xpresion::T_OP & $opc->type) && (0 === $opc->arity) )
                    {
                        array_push($token_queue,Xpresion::$EMPTY_TOKEN->node(null, $pos+1));
                    }
                }

                else // if (Xpresion::INFIX === $fixity)
                {
                    while (count($op_queue) > $nop_index)
                    {
                        $entry = array_shift($op_queue);
                        $op = $entry->node;

                        if (
                            ($op->priority < $opc->priority) ||
                            ($op->priority === $opc->priority &&
                                ($op->associativity < $opc->associativity ||
                                    ($op->associativity === $opc->associativity &&
                                        $op->associativity < 0)))
                        )
                        {
                            $arity = $op->arity;
                            if ( (Xpresion::T_OP & $op->type) && 0 === $arity ) $arity = 1; // have already padded with empty token
                            elseif ( $arity > count($token_queue) && $op->arity_min <= $op->arity ) $arity = $op->arity_min;
                            $n = $op->node(array_splice($token_queue, -$arity, $arity), $entry->pos);
                            array_push($token_queue, $n);
                        }
                        else
                        {
                            array_unshift($op_queue, $entry);
                            break;
                        }
                    }
                    array_unshift($op_queue, new XpresionNode($opc->otype, $opc->arity, $opc, null, $pos));
                }
            }
        }
        else
        {
            while (!empty($op_queue))
            {
                $entry = array_shift($op_queue);
                $op = $entry->node;
                $arity = $op->arity;
                if ( (Xpresion::T_OP & $op->type) && 0 === $arity ) $arity = 1; // have already padded with empty token
                elseif ( $arity > count($token_queue) && $op->arity_min <= $op->arity ) $arity = $op->arity_min;
                $n = $op->node(array_splice($token_queue, -$arity, $arity), $entry->pos);
                array_push($token_queue, $n);
            }
        }
    }

    public static function parse_delimited_block($s, $i, $l, $delim, $is_escaped=true)
    {
        $p = $delim;
        $esc = false;
        $ch = '';
        $is_escaped = (bool)(false !== $is_escaped);
        $i += 1;
        while ($i < $l)
        {
            $ch = $s[$i++];
            $p .= $ch;
            if ($delim === $ch && !$esc) break;
            $esc = $is_escaped ? (!$esc && ('\\' === $ch)) : false;
        }
        return $p;
    }

    public static function parse($xpr)
    {
        $RE =& $xpr->RE;
        $BLOCK =& $xpr->BLOCKS;
        $t_var_is_also_ident = !isset($RE['t_var']);

        $err = 0;
        $errors = (object)array('err'=> false, 'msg'=> '');
        $expr = $xpr->source;
        $l = strlen($expr);
        $xpr->_cnt = 0;
        $xpr->_symbol_table = array();
        $xpr->_cache = new \stdClass;
        $xpr->variables = array();
        $AST = array();
        $OPS = array();
        $NOPS = array();
        $t_index = 0;
        $i = 0;

        while ($i < $l)
        {
            $ch = $expr[ $i ];

            // use customized (escaped) delimited blocks here
            // TODO: add a "date" block as well with #..#
            $block = XpresionAlias::get_entry($BLOCK, $ch);
            if ($block) // string or regex or date ('"`#)
            {
                $v = call_user_func($block['parse'], $expr, $i, $l, $ch);
                if (false !== $v)
                {
                    $i += strlen($v);
                    if (isset($block['rest']))
                    {
                        $block_rest = call_user_func($block['rest'], $expr, $i, $l);
                        if (!$block_rest) $block_rest = '';
                    }
                    else
                    {
                        $block_rest = '';
                    }

                    $i += strlen($block_rest);

                    $t = $xpr->t_block( $v, $block['type'], $block_rest );
                    if (false !== $t)
                    {
                        $t_index+=1;
                        array_push($AST, $t->node(null, $t_index));
                        continue;
                    }
                }
            }

            $e = substr($expr, $i);

            if (preg_match($RE['t_spc'], $e, $m)) // space
            {
                $i += strlen($m[ 0 ]);
                continue;
            }

            if (preg_match($RE['t_num'], $e, $m)) // number
            {
                $t = $xpr->t_liter( $m[ 1 ], Xpresion::T_NUM );
                if (false !== $t)
                {
                    $t_index+=1;
                    array_push($AST, $t->node(null, $t_index));
                    $i += strlen($m[ 0 ]);
                    continue;
                }
            }

            if (preg_match($RE['t_ident'], $e, $m)) // ident, reserved, function, operator, etc..
            {
                $t = $xpr->t_liter( $m[ 1 ], Xpresion::T_IDE ); // reserved keyword
                if (false !== $t)
                {
                    $t_index+=1;
                    array_push($AST, $t->node(null, $t_index));
                    $i += strlen($m[ 0 ]);
                    continue;
                }

                $t = $xpr->t_op( $m[ 1 ] ); // (literal) operator
                if (false !== $t)
                {
                    $t_index+=1;
                    Xpresion::reduce( $AST, $OPS, $NOPS, $t, $t_index, $errors );
                    if ( $errors->err )
                    {
                        $err = 1;
                        $errmsg = $errors->msg;
                        break;
                    }
                    $i += strlen($m[ 0 ]);
                    continue;
                }

                if ($t_var_is_also_ident)
                {
                    $t = $xpr->t_var( $m[ 1 ] ); // variables are also same identifiers
                    if (false !== $t)
                    {
                        $t_index+=1;
                        array_push($AST, $t->node(null, $t_index));
                        $i += strlen($m[ 0 ]);
                        continue;
                    }
                }
            }

            if (preg_match($RE['t_special'], $e, $m)) // special symbols..
            {
                $v = $m[ 1 ];
                $t = false;
                while (strlen($v) > 0) // try to match maximum length op/func
                {
                    $t = $xpr->t_op( $v ); // function, (non-literal) operator
                    if (false !== $t) break;
                    $v = substr($v,0,-1);
                }
                if (false !== $t)
                {
                    $t_index+=1;
                    Xpresion::reduce( $AST, $OPS, $NOPS, $t, $t_index, $errors );
                    if ( $errors->err )
                    {
                        $err = 1;
                        $errmsg = $errors->msg;
                        break;
                    }
                    $i += strlen($v);
                    continue;
                }
            }

            if (!$t_var_is_also_ident)
            {
                if (preg_match($RE['t_var'], $e, $m)) // variables
                {
                    $t = $xpr->t_var( $m[ 1 ] );
                    if (false !== $t)
                    {
                        $t_index+=1;
                        array_push($AST, $t->node(null, $t_index));
                        $i += strlen($m[ 0 ]);
                        continue;
                    }
                }
            }


            if (preg_match($RE['t_nonspc'], $e, $m)) // other non-space tokens/symbols..
            {
                $t = $xpr->t_liter( $m[ 1 ], Xpresion::T_LIT ); // reserved keyword
                if (false !== $t)
                {
                    $t_index+=1;
                    array_push($AST, $t->node(null, $t_index));
                    $i += strlen($m[ 0 ]);
                    continue;
                }

                $t = $xpr->t_op( $m[ 1 ] ); // function, other (non-literal) operator
                if (false !== $t)
                {
                    $t_index+=1;
                    Xpresion::reduce( $AST, $OPS, $NOPS, $t, $t_index, $errors );
                    if ( $errors->err )
                    {
                        $err = 1;
                        $errmsg = $errors->msg;
                        break;
                    }
                    $i += strlen($m[ 0 ]);
                    continue;
                }

                $t = $xpr->t_tok( $m[ 1 ] );
                $t_index+=1;
                array_push($AST, $t->node(null, $t_index)); // pass-through ..
                $i += strlen($m[ 0 ]);
                //continue
            }
        }

        if ( !$err )
        {
            Xpresion::reduce( $AST, $OPS, $NOPS );

            if ((1 !== count($AST)) || !empty($OPS))
            {
                $err = 1;
                $errmsg = 'Parse Error, Mismatched Parentheses or Operators';
            }
        }

        if (!$err)
        {
            try {

                $evaluator = $xpr->compile( $AST[0] );
            }
            catch (\Exception $ex) {

                $err = 1;
                $errmsg = 'Compilation Error, ' . $ex->getMessage() . '';
            }
        }

        $NOPS = null;
        $OPS = null;
        $AST = null;
        $xpr->_symbol_table = null;

        if ($err)
        {
            $evaluator = null;
            $xpr->variables = array();
            $xpr->_cnt = 0;
            $xpr->_cache = new \stdClass;
            $xpr->_evaluator_str = '';
            $xpr->_evaluator = $xpr->dummy_evaluator;
            echo( 'Xpresion Error: ' . $errmsg . ' at ' . $expr . "\n");
        }
        else
        {
            // make array
            $xpr->variables = array_keys( $xpr->variables );
            $xpr->_evaluator_str = $evaluator[0];
            $xpr->_evaluator = $evaluator[1];
        }

        return $xpr;
    }

    public static function render($tok, $args=null)
    {
        if (null===$args) $args=array();
        return $tok->render( $args );
    }

    public static function &defRE($obj, &$RE=null)
    {
        if (is_array($obj) || is_object($obj))
        {
            if (!$RE) $RE =& Xpresion::$RE_S;
            foreach ((array)$obj as $k=>$v) $RE[ $k ] = $v;
        }
        return $RE;
    }

    public static function &defBlock($obj, &$BLOCK=null)
    {
        if (is_array($obj) || is_object($obj))
        {
            if (!$BLOCK) $BLOCK =& Xpresion::$BLOCKS_S;
            foreach ((array)$obj as $k=>$v) $BLOCK[ $k ] = $v;
        }
        return $BLOCK;
    }

    public static function &defReserved($obj, &$Reserved=null)
    {
        if (is_array($obj) || is_object($obj))
        {
            if (!$Reserved) $Reserved =& Xpresion::$Reserved_S;
            foreach ((array)$obj as $k=>$v) $Reserved[ $k ] = $v;
        }
        return $Reserved;
    }

    public static function &defOp($obj, &$OPERATORS=null)
    {
        if (is_array($obj) || is_object($obj))
        {
            if (!$OPERATORS) $OPERATORS =& Xpresion::$OPERATORS_S;
            foreach ((array)$obj as $k=>$v) $OPERATORS[ $k ] = $v;
        }
        return $OPERATORS;
    }

    public static function &defFunc($obj, &$FUNCTIONS=null)
    {
        if (is_array($obj) || is_object($obj))
        {
            if (!$FUNCTIONS) $FUNCTIONS =& Xpresion::$FUNCTIONS_S;
            foreach ((array)$obj as $k=>$v) $FUNCTIONS[ $k ] = $v;
        }
        return $FUNCTIONS;
    }

    public static function &defRuntimeFunc($obj, &$Fn=null)
    {
        if (is_array($obj) || is_object($obj))
        {
            if (!$Fn)
            {
                $FnS = Xpresion::$Fn_S;
                $Fn =& $FnS->Fn;
            }
            foreach ((array)$obj as $k=>$v) $Fn[ $k ] = $v;
        }
        return $Fn;
    }

    public $source = null;
    public $variables = null;

    public $RE = null;
    public $Reserved = null;
    public $BLOCKS = null;
    public $OPERATORS = null;
    public $FUNCTIONS = null;
    public $Fn = null;

    public $_cnt = 0;
    public $_cache = null;
    public $_symbol_table = null;
    public $_evaluator_str = null;
    public $_evaluator = null;
    public $dummy_evaluator = null;

    public function __construct($expr=null)
    {
        $this->source = $expr ? strval($expr) : '';
        $this->setup( );
        Xpresion::parse( $this );
    }

    public function __destruct()
    {
        $this->dispose();
    }

    public function dispose()
    {
        $this->RE = null;
        $this->Reserved = null;
        $this->BLOCKS = null;
        $this->OPERATORS = null;
        $this->FUNCTIONS = null;
        $this->Fn = null;
        $this->dummy_evaluator = null;

        $this->source = null;
        $this->variables = null;

        $this->_cnt = null;
        $this->_symbol_table = null;
        $this->_cache = null;
        $this->_evaluator_str = null;
        $this->_evaluator = null;

        return $this;
    }

    public function setup()
    {
        $this->RE = Xpresion::$RE_S;
        $this->Reserved = Xpresion::$Reserved_S;
        $this->BLOCKS = Xpresion::$BLOCKS_S;
        $this->OPERATORS = Xpresion::$OPERATORS_S;
        $this->FUNCTIONS = Xpresion::$FUNCTIONS_S;
        $this->Fn = Xpresion::$Fn_S;
        $this->dummy_evaluator = XpresionUtils::$dummy;
        return $this;
    }

    public function compile($AST)
    {
        // depth-first traversal and rendering of Abstract Syntax Tree (AST)
        $evaluator_str = XpresionNode::DFT( $AST, array(__NAMESPACE__ . '\Xpresion','render'), true );
        return array($evaluator_str, XpresionUtils::evaluator_factory($evaluator_str, $this->Fn, $this->_cache));
    }

    public function evaluator($evaluator=null)
    {
        if (func_num_args())
        {
            if (is_callable($evaluator)) $this->_evaluator = $evaluator;
            return $this;
        }
        return $this->_evaluator;
    }

    public function evaluate($data=array())
    {
        $e = $this->_evaluator;
        return call_user_func($e, $data);
    }

    public function debug($data=null)
    {
        $out = array(
            'expression' => $this->source,
            'variables' => $this->variables,
            'evaluator' => '' . $this->_evaluator_str
        );
        if (null!==$data)
        {
            //ob_start();
            //var_dump($this->evaluate($data));
            //$output = ob_get_clean();
            $output = var_export($this->evaluate($data), true);
            $out[] = 'Data      : ' . print_r($data, true);
            $out[] = 'Result    : ' . $output;
        }
        return $out;
    }

    public function __toString()
    {
        return '[Xpresion source]: ' . $this->source . '';
    }

    public function t_liter($token, $type)
    {
        if (Xpresion::T_NUM === $type) return Xpresion::Tok(Xpresion::T_NUM, $token, $token);
        return XpresionAlias::get_entry($this->Reserved, strtolower($token));
    }

    public function t_block($token, $type, $rest='')
    {
        if (Xpresion::T_STR === $type)
        {
            return Xpresion::Tok(Xpresion::T_STR, $token, $token);
        }

        elseif (Xpresion::T_REX === $type)
        {
            $sid = 're_'.$token.$rest;
            if (isset($this->_symbol_table[$sid]))
            {
                $id = $this->_symbol_table[$sid];
            }
            else
            {
                $this->_cnt += 1;
                $id = 're_' . $this->_cnt;
                $flags = '';
                if (false !== strpos($rest, 'i')) $flags.= 'i';
                $this->_cache->{$id} = $token . $flags;
                $this->_symbol_table[$sid] = $id;
            }
            return Xpresion::Tok(Xpresion::T_REX, $token, '$Cache->'.$id);
        }
        /*elif T_DTM == type:
            rest = (rest || '').slice(1,-1);
            var sid = 'dt_'+token+rest, id, rs;
            if ( this._symbol_table[HAS](sid) )
            {
                id = this._symbol_table[sid];
            }
            else
            {
                id = 'dt_' + (++this._cnt);
                rs = token.slice(1,-1);
                this._cache[ id ] = DATE(rs, rest);
                this._symbol_table[sid] = id;
            }
            return Tok(T_DTM, token, 'Cache.'+id+'');*/
        return false;
    }

    public function t_var($token)
    {
        if (!isset($this->variables[$token])) $this->variables[ $token ] = $token;
        return Xpresion::Tok(Xpresion::T_VAR, $token, '$Var["' . implode('"]["', explode('.', $token)) . '"]');
    }

    public function t_op($token)
    {
        $op = false;
        $op = XpresionAlias::get_entry($this->FUNCTIONS, $token);
        if (false === $op) $op = XpresionAlias::get_entry($this->OPERATORS, $token);
        return $op;
    }

    public function t_tok($token)
    {
        return Xpresion::Tok(Xpresion::T_DFT, $token, $token);
    }

    public static function _($expr=null)
    {
        return new Xpresion($expr);
    }

    public static function init( $andConfigure=false )
    {
        if (self::$_inited) return;
        Xpresion::$OPERATORS_S = array();
        Xpresion::$FUNCTIONS_S = array();
        Xpresion::$Fn_S = new XpresionFn();
        Xpresion::$RE_S = array();
        Xpresion::$BLOCKS_S = array();
        Xpresion::$Reserved_S = array();
        Xpresion::$EMPTY_TOKEN = Xpresion::Tok(Xpresion::T_EMPTY, '', '');
        XpresionUtils::$dummy = create_function('$Var', 'return null;');
        self::$_inited = true;
        if ( true === $andConfigure ) Xpresion::defaultConfiguration( );
    }

    public static function defaultConfiguration( )
    {
        if (self::$_configured) return;

        Xpresion::defOp(array(
            //----------------------------------------------------------------------------------------------------------------------
            //symbol    input               ,fixity                 ,associativity      ,priority   ,output         ,output_type
            //----------------------------------------------------------------------------------------------------------------------
            // bra-kets as n-ary operators
            // negative number of arguments, indicate optional arguments (experimental)
            '('    =>  Xpresion::Op(
                array('(',-1,')')   ,Xpresion::POSTFIX      ,Xpresion::RIGHT    ,0          ,'$0'           ,Xpresion::T_DUM
            )
        ,')'    =>  Xpresion::Op(array(-1,')'))
        ,'['    =>  Xpresion::Op(
                array('[',-1,']')   ,Xpresion::POSTFIX      ,Xpresion::RIGHT    ,2          ,'array($0)'    ,Xpresion::T_ARY
            )
        ,']'    =>  Xpresion::Op(array(-1,']'))
        ,','    =>  Xpresion::Op(
                array(1,',',1)      ,Xpresion::INFIX        ,Xpresion::LEFT     ,3          ,'$0,$1'        ,Xpresion::T_DFT
            )
            // n-ary (ternary) if-then-else operator
        ,'?'    =>  Xpresion::Op(
                array(1,'?',1,':',1) ,Xpresion::INFIX       ,Xpresion::RIGHT    ,100        ,'($0?$1:$2)'   ,Xpresion::T_BOL
            )
        ,':'    =>  Xpresion::Op(array(1,':',1))
        ,'!'    =>  Xpresion::Op(
                array('!',1)        ,Xpresion::PREFIX       ,Xpresion::RIGHT    ,10         ,'!$0'          ,Xpresion::T_BOL
            )
        ,'~'    =>  Xpresion::Op(
                array('~',1)        ,Xpresion::PREFIX       ,Xpresion::RIGHT    ,10         ,'~$0'          ,Xpresion::T_NUM
            )
        ,'^'    =>  Xpresion::Op(
                array(1,'^',1)      ,Xpresion::INFIX        ,Xpresion::RIGHT    ,11         ,'pow($0,$1)'   ,Xpresion::T_NUM
            )
        ,'*'    =>  Xpresion::Op(
                array(1,'*',1)      ,Xpresion::INFIX        ,Xpresion::LEFT     ,20         ,'($0*$1)'      ,Xpresion::T_NUM
            )
        ,'/'    =>  Xpresion::Op(
                array(1,'/',1)      ,Xpresion::INFIX        ,Xpresion::LEFT     ,20         ,'($0/$1)'      ,Xpresion::T_NUM
            )
        ,'%'    =>  Xpresion::Op(
                array(1,'%',1)      ,Xpresion::INFIX        ,Xpresion::LEFT     ,20         ,'($0%$1)'      ,Xpresion::T_NUM
            )
            // addition/concatenation/unary plus as polymorphic operators
        ,'+'    =>  Xpresion::Op()->Polymorphic(array(
                // array concatenation
                array('${TOK} and (!${PREV_IS_OP}) and (${DEDUCED_TYPE}==='. __NAMESPACE__ .'\Xpresion::T_ARY)', Xpresion::Op(
                    array(1,'+',1)      ,Xpresion::INFIX        ,Xpresion::LEFT     ,25         ,'$Fn->ary_merge($0,$1)'    ,Xpresion::T_ARY
                ))
                // string concatenation
            ,array('${TOK} and (!${PREV_IS_OP}) and (${DEDUCED_TYPE}==='. __NAMESPACE__ .'\Xpresion::T_STR)', Xpresion::Op(
                    array(1,'+',1)      ,Xpresion::INFIX        ,Xpresion::LEFT     ,25         ,'($0.strval($1))'  ,Xpresion::T_STR
                ))
                // numeric addition
            ,array('${TOK} and (!${PREV_IS_OP})', Xpresion::Op(
                    array(1,'+',1)      ,Xpresion::INFIX        ,Xpresion::LEFT     ,25         ,'($0+$1)'      ,Xpresion::T_NUM
                ))
                // unary plus
            ,array('!${TOK} or ${PREV_IS_OP}', Xpresion::Op(
                    array('+',1)        ,Xpresion::PREFIX       ,Xpresion::RIGHT    ,4          ,'$0'           ,Xpresion::T_NUM
                ))
            ))
        ,'-'    =>  Xpresion::Op()->Polymorphic(array(
                // numeric subtraction
                array('${TOK} and (!${PREV_IS_OP})', Xpresion::Op(
                    array(1,'-',1)      ,Xpresion::INFIX        ,Xpresion::LEFT     ,25         ,'($0-$1)'      ,Xpresion::T_NUM
                ))
                // unary negation
            ,array('!${TOK} or ${PREV_IS_OP}', Xpresion::Op(
                    array('-',1)        ,Xpresion::PREFIX       ,Xpresion::RIGHT    ,4          ,'(-$0)'        ,Xpresion::T_NUM
                ))
            ))
        ,'>>'   =>  Xpresion::Op(
                array(1,'>>',1)     ,Xpresion::INFIX        ,Xpresion::LEFT     ,30         ,'($0>>$1)'     ,Xpresion::T_NUM
            )
        ,'<<'   =>  Xpresion::Op(
                array(1,'<<',1)     ,Xpresion::INFIX        ,Xpresion::LEFT     ,30         ,'($0<<$1)'     ,Xpresion::T_NUM
            )
        ,'>'    =>  Xpresion::Op(
                array(1,'>',1)      ,Xpresion::INFIX        ,Xpresion::LEFT     ,35         ,'($0>$1)'      ,Xpresion::T_BOL
            )
        ,'<'    =>  Xpresion::Op(
                array(1,'<',1)      ,Xpresion::INFIX        ,Xpresion::LEFT     ,35         ,'($0<$1)'      ,Xpresion::T_BOL
            )
        ,'>='   =>  Xpresion::Op(
                array(1,'>=',1)     ,Xpresion::INFIX        ,Xpresion::LEFT     ,35         ,'($0>=$1)'     ,Xpresion::T_BOL
            )
        ,'<='   =>  Xpresion::Op(
                array(1,'<=',1)     ,Xpresion::INFIX        ,Xpresion::LEFT     ,35         ,'($0<=$1)'     ,Xpresion::T_BOL
            )
        ,'=='   =>  Xpresion::Op()->Polymorphic(array(
                // array equivalence
                array('${DEDUCED_TYPE}==='. __NAMESPACE__ . '\Xpresion::T_ARY', Xpresion::Op(
                    array(1,'==',1)     ,Xpresion::INFIX        ,Xpresion::LEFT     ,40         ,'$Fn->ary_eq($0,$1)'   ,Xpresion::T_BOL
                ))
                // default equivalence
            ,array('true', Xpresion::Op(
                    array(1,'==',1)     ,Xpresion::INFIX        ,Xpresion::LEFT     ,40         ,'($0==$1)'     ,Xpresion::T_BOL
                ))
            ))
        ,'!='   =>  Xpresion::Op(
                array(1,'!=',1)     ,Xpresion::INFIX        ,Xpresion::LEFT     ,40         ,'($0!=$1)'     ,Xpresion::T_BOL
            )
        ,'is'   =>  Xpresion::Op(
                array(1,'is',1)     ,Xpresion::INFIX        ,Xpresion::LEFT     ,40         ,'($0===$1)'    ,Xpresion::T_BOL
            )
        ,'matches'=>Xpresion::Op(
                array(1,'matches',1) ,Xpresion::INFIX       ,Xpresion::NONE     ,40         ,'$Fn->match($1,$0)'    ,Xpresion::T_BOL
            )
        ,'in'   =>  Xpresion::Op(
                array(1,'in',1)     ,Xpresion::INFIX        ,Xpresion::NONE     ,40         ,'$Fn->contains($1,$0)'      ,Xpresion::T_BOL
            )
        ,'&'    =>  Xpresion::Op(
                array(1,'&',1)      ,Xpresion::INFIX        ,Xpresion::LEFT     ,45         ,'($0&$1)'      ,Xpresion::T_NUM
            )
        ,'|'    =>  Xpresion::Op(
                array(1,'|',1)      ,Xpresion::INFIX        ,Xpresion::LEFT     ,46         ,'($0|$1)'      ,Xpresion::T_NUM
            )
        ,'&&'   =>  Xpresion::Op(
                array(1,'&&',1)     ,Xpresion::INFIX        ,Xpresion::LEFT     ,47         ,'($0&&$1)'     ,Xpresion::T_BOL
            )
        ,'||'   =>  Xpresion::Op(
                array(1,'||',1)     ,Xpresion::INFIX        ,Xpresion::LEFT     ,48         ,'($0||$1)'     ,Xpresion::T_BOL
            )
            //------------------------------------------
            //                aliases
            //-------------------------------------------
        ,'or'   =>  Xpresion::Alias( '||' )
        ,'and'  =>  Xpresion::Alias( '&&' )
        ,'not'  =>  Xpresion::Alias( '!' )
        ));

        Xpresion::defFunc(array(
            //----------------------------------------------------------------------------------------------------------
            //symbol                    input       ,output             ,output_type    ,priority(default 1)    ,arity(default 1)
            //----------------------------------------------------------------------------------------------------------
            'min'      => Xpresion::Func('min'     ,'min($0)'          ,Xpresion::T_NUM  )
        ,'max'      => Xpresion::Func('max'     ,'max($0)'          ,Xpresion::T_NUM  )
        ,'pow'      => Xpresion::Func('pow'     ,'pow($0)'          ,Xpresion::T_NUM  )
        ,'sqrt'     => Xpresion::Func('sqrt'    ,'sqrt($0)'         ,Xpresion::T_NUM  )
        ,'len'      => Xpresion::Func('len'     ,'$Fn->len($0)'     ,Xpresion::T_NUM  )
        ,'int'      => Xpresion::Func('int'     ,'intval($0)'       ,Xpresion::T_NUM  )
        ,'str'      => Xpresion::Func('str'     ,'strval($0)'       ,Xpresion::T_STR  )
        ,'clamp'    => Xpresion::Func('clamp'   ,'$Fn->clamp($0)'   ,Xpresion::T_NUM  )
        ,'sum'      => Xpresion::Func('sum'     ,'$Fn->sum($0)'     ,Xpresion::T_NUM  )
        ,'avg'      => Xpresion::Func('avg'     ,'$Fn->avg($0)'     ,Xpresion::T_NUM  )
        ,'time'     => Xpresion::Func('time'    ,'time()'           ,Xpresion::T_NUM    ,1                  ,0  )
        ,'date'     => Xpresion::Func('date'    ,'date($0)'         ,Xpresion::T_STR  )
            //---------------------------------------
            //                aliases
            //----------------------------------------
            // ...
        ));

        // function implementations (can also be overriden per instance/evaluation call)
        //Xpresion::$Fn_S = new XpresionFn();

        Xpresion::defRE(array(
            //-----------------------------------------------
            //token                re
            //-------------------------------------------------
            't_spc'        =>  '/^(\\s+)/'
        ,'t_nonspc'     =>  '/^(\\S+)/'
        ,'t_special'    =>  '/^([*.\\-+\\\\\\/\^\\$\\(\\)\\[\\]|?<:>&~%!#@=_,;{}]+)/'
        ,'t_num'        =>  '/^(\\d+(\\.\\d+)?)/'
        ,'t_ident'      =>  '/^([a-zA-Z_][a-zA-Z0-9_]*)\\b/'
        ,'t_var'        =>  '/^\\$([a-zA-Z0-9_][a-zA-Z0-9_.]*)\\b/'
        ));

        Xpresion::defBlock(array(
            '\''=> array(
                'type'=> Xpresion::T_STR,
                'parse'=> array(__NAMESPACE__ . '\Xpresion','parse_delimited_block')
            )
        ,'"'=> Xpresion::Alias('\'')
        ,'`'=> array(
                'type'=> Xpresion::T_REX,
                'parse'=> array(__NAMESPACE__ . '\Xpresion','parse_delimited_block'),
                'rest'=> array(__NAMESPACE__ .'\XpresionUtils','parse_re_flags')
            )
            /*,'#': {
                type: T_DTM,
                parse: Xpresion.parse_delimited_block,
                rest: function(s,i,l){
                    var rest = '"Y-m-d"', ch = i < l ? s.charAt( i ) : '';
                    if ( '"' === ch || "'" === ch )
                        rest = Xpresion.parse_delimited_block(s,i,l,ch,true);
                    return rest;
                }
            }*/
        ));

        Xpresion::defReserved(array(
            'null'     => Xpresion::Tok(Xpresion::T_IDE, 'null', 'null')
        ,'false'    => Xpresion::Tok(Xpresion::T_BOL, 'false', 'false')
        ,'true'     => Xpresion::Tok(Xpresion::T_BOL, 'true', 'true')
        ,'infinity' => Xpresion::Tok(Xpresion::T_NUM, 'Infinity', '$Fn->INF')
        ,'nan'      => Xpresion::Tok(Xpresion::T_NUM, 'NaN', '$Fn->NAN')
            // aliases
        ,'none'     => Xpresion::Alias('null')
        ,'inf'      => Xpresion::Alias('infinity')
        ));

        self::$_configured = true;
    }

}