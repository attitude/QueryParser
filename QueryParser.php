<?php

namespace attitude\QueryParser;

use \attitude\Exception\Exception;

/**
 * Search query parser class
 *
 * Parses search engine queries into array a (query tree).
 *
 */
class QueryParser
{
    protected static $instance;
    protected static $tokens;

    /**
     * Default static parse method to be called
     *
     * @param string $q Query string
     * @return array Nested result as query tree
     *
     */
    public static function parse($q)
    {
        if (!is_string($q)) {
            throw new Exception(500, __FUNCTION__.'() expects string as input');
        }

        $q = trim($q);

        if (strlen($q) === 0) {
            return array();
        }

        // Init instance
        if (!isset(static::$instance)) {
            static::$instance = new self;
        }

        // Put into parenthesis
        if (!strstr($q, '(')) {
            $q = '('.$q.')';
        }

        return static::$instance->tokenizeParenthesis($q);
    }

    /**
     * Split a string into an array of space-delimited tokens,
     * taking double-quoted and single-quoted strings into account
     *
     * Modified to clean string and to add support for `AND`, `OR`, `NOT`.
     *
     * @see http://stackoverflow.com/questions/3811519/regular-expressions-for-google-operators
     *
     */
    protected function tokenizeQuoted($q, $quotationMarks='"\'')
    {
        // multiple white-space to single
        $q = preg_replace('/(\s+)/', ' ', $q);

        // UNIFY No need for `AND`
        $q = str_ireplace('and ', '', $q);

        // UNIFY: Replace `NOT[space]word` with `-word`
        $q = str_ireplace('not ', '-', $q);
        $q = str_ireplace('- ', '-', $q);

        // Put `-` inside of the ""
        $q = str_replace('-"', '"-', $q);

        // Prepare result array
        $tokens = array();

        for ($nextToken=strtok($q, ' '); $nextToken!==false; $nextToken=strtok(' ')) {
            if (strpos($quotationMarks, $nextToken[0]) !== false) {
                if (strpos($quotationMarks, $nextToken[strlen($nextToken)-1]) !== false) {
                    $tokens[] = substr($nextToken, 1, -1);
                } else {
                    $tokens[] = substr($nextToken, 1) . ' ' . strtok($nextToken[0]);
                }
            } else {
                $tokens[] = $nextToken;
            }
        }

        // Phase 1: replace strings with Expressions
        foreach ($tokens as $i => $token) {
            if (strtoupper($token) === 'OR') {
                continue;
            }

            if (strstr($tokens[$i], ':')) {
                $token = explode(':', $token);

                $tokens[$i] = array(
                    'Expression' => 'Explicit Term',
                    'Nominator' => $token[0],
                    'Term' => $token[1]
                );
            } elseif (is_string($tokens[$i])) {
                $tokens[$i] = array(
                    'Expression' => 'Word',
                    'Term' => $tokens[$i]
                );
            }
        }

        // Phase 2: Negation
        foreach ($tokens as $i => $token) {
            if (strtoupper($token) === 'OR') {
                continue;
            }

            if ($tokens[$i]['Term'][0] === '-') {
                // Substract `-`
                $tokens[$i]['Term'] = substr($tokens[$i]['Term'], 1);

                // Wrap
                $tokens[$i] = array(
                    'Operator' => 'NOT', 'Expression' => $tokens[$i]
                );
            }
        }

        foreach ($tokens as $i => $token) {
            if (strtoupper($token) === 'OR') {
                if ($i > 0 && $i < sizeof($tokens) - 1) {
                    $tokens[($i+1)] = array(
                        'Operator' => 'OR',
                        'Expressions' => array(
                            $tokens[($i-1)],
                            array(
                                'Expression' => 'Word',
                                'Term' => $tokens[$i+1]
                            )
                        )
                    );

                    // Temporarily set as null
                    $tokens[$i-1] = null;
                }

                // Temporarily set as null
                $tokens[$i] = null;
            }
        }

        // Clean up
        foreach ($tokens as $i => $token) {
            if ($token === null) {
                unset($tokens[$i]);
            }
        }

        return array(
            'Operator' => 'AND',
            'Expressions' => array_values($tokens)
        );
    }

    /**
     * Parses the string grouped in parenthesis and returns nested tree
     * of expressions with operators.
     *
     * @param string $q Query as string
     * @param string $opening Delimits start of the token
     * @param string $closing Delimits end of the token
     * @param string $operator Logical operator meaning
     * @return array Nested array with operators and expressions
     *
     */
    protected function tokenizeParenthesis($q, $opening = '(', $closing = ')', $operator = 'AND')
    {
        $buffer = '';

        $tokenPositions = array();

        $results = array(
            'Operator' => $operator,
            'Expressions' => array(
                null
            )
        );

        $tokens = array(
            &$results['Expressions']
        );

        $q = trim($q);

        for ($i=0; $i < strlen($q); $i += 1)
        {
            if ($q[$i] === $closing) {
                //// >>>

                $lastTokenIndex = sizeof($tokens) - 1;

                if ($tokens[ $lastTokenIndex ][ sizeof($tokens[ $lastTokenIndex ])-1 ] !== null) {
                    $lastToken = array_pop($tokens[ $lastTokenIndex ]);

                    $lastToken = $this->tokenizeQuoted($lastToken);

                    $tokens[ $lastTokenIndex ] = array_merge($tokens[ $lastTokenIndex ], array($lastToken));
                }

                //////

                // Finish nesting of pointer (pop off the last pointer)
                array_pop($tokens);
                $tokens[ sizeof($tokens)-1 ][] = null;
            } else {
                if ($q[$i] === $opening) {
                    //// >>>

                    $lastTokenIndex = sizeof($tokens) - 1;

                    if ($tokens[ $lastTokenIndex ][ sizeof($tokens[ $lastTokenIndex ])-1 ] !== null) {
                        $lastToken = array_pop($tokens[ $lastTokenIndex ]);

                        $lastToken = $this->tokenizeQuoted($lastToken);

                        $tokens[ $lastTokenIndex ] = array_merge($tokens[ $lastTokenIndex ], array($lastToken));
                    }

                    //////

                    $next =
                        $tokens[ sizeof($tokens)-1 ][ sizeof($tokens[ sizeof($tokens)-1 ]) -1 ] === null ?
                        sizeof($tokens[ sizeof($tokens)-1 ]) -1 :
                        sizeof($tokens[ sizeof($tokens)-1 ]);

                    // Init new nesting on pointer
                    $tokens[ sizeof($tokens)-1 ][$next] = array(
                        'Operator' => $operator,
                        'Expressions' => array(
                            null
                        )
                    );

                    // Add new last pointer
                    $tokens[] =& $tokens[ sizeof($tokens)-1 ][$next]['Expressions'];
                } else {
                    $lastTokenIndex = sizeof($tokens) - 1;

                    // Initialize new string
                    if ($tokens[ $lastTokenIndex ][ sizeof($tokens[ $lastTokenIndex ])-1 ] === null) {
                        $tokens[ $lastTokenIndex ][ sizeof($tokens[ $lastTokenIndex ])-1 ] = '';
                    }

                    // Write to the last
                    $tokens[ $lastTokenIndex ][ sizeof($tokens[ $lastTokenIndex ])-1 ] .= $q[$i];
                }
            }
        }

        //// >>>

        $lastTokenIndex = sizeof($tokens) - 1;

        if ($tokens[ $lastTokenIndex ][ sizeof($tokens[ $lastTokenIndex ])-1 ] !== null) {
            $lastToken = array_pop($tokens[ $lastTokenIndex ]);

            $lastToken = $this->tokenizeQuoted($lastToken);

            $tokens[ $lastTokenIndex ] = array_merge($tokens[ $lastTokenIndex ], array($lastToken));
        } else {
            unset($tokens[ $lastTokenIndex ][ sizeof($tokens[ $lastTokenIndex ])-1 ]);
        }

        //////

        return $results;
    }
}
