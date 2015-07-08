<?php


namespace Xpresion;



class XpresionTok
{
    public static function render_tok($t)
    {
        if ($t instanceof XpresionTok) return $t->render();
        return strval($t);
    }

    public $type = null;
    public $input = null;
    public $output = null;
    public $value = null;
    public $priority = null;
    public $parity = null;
    public $arity = null;
    public $arity_min = null;
    public $arity_max = null;
    public $associativity = null;
    public $fixity = null;
    public $parenthesize = null;
    public $revert = null;

    public function __construct($type, $input, $output, $value=null)
    {
        $this->type = $type;
        $this->input = $input;
        $this->output = $output;
        $this->value = $value;
        $this->priority = 1000;
        $this->parity = 0;
        $this->arity = 0;
        $this->arity_min = 0;
        $this->arity_max = 0;
        $this->associativity = Xpresion::DEFAUL;
        $this->fixity = Xpresion::INFIX;
        $this->parenthesize = false;
        $this->revert = false;
    }

    public function __destruct()
    {
        $this->dispose();
    }

    public function dispose()
    {
        $this->type = null;
        $this->input = null;
        $this->output = null;
        $this->value = null;
        $this->priority = null;
        $this->parity = null;
        $this->arity = null;
        $this->arity_min = null;
        $this->arity_max = null;
        $this->associativity = null;
        $this->fixity = null;
        $this->parenthesize = null;
        $this->revert = null;
        return $this;
    }

    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    public function setParenthesize($bol)
    {
        $this->parenthesize = (bool)$bol;
        return $this;
    }

    public function setReverse($bol)
    {
        $this->revert = (bool)$bol;
        return $this;
    }

    public function render($args=null)
    {
        $token = $this->output;
        $p = $this->parenthesize;
        $lparen = $p ? Xpresion::LPAREN : '';
        $rparen = $p ? Xpresion::RPAREN : '';
        if (null===$args) $args=array();
        array_unshift($args, $this->input);

        if ($token instanceof XpresionTpl)   $out = $token->render( $args );
        else                                 $out = strval($token);
        return $lparen . $out . $rparen;
    }

    public function node($args=null, $pos=0)
    {
        return new XpresionNode($this->type, $this->arity, $this, $args ? $args : null, $pos);
    }

    public function __toString()
    {
        return strval($this->output);
    }
}