<?php
namespace GraphQL\Tests\Executor;

require_once __DIR__ . '/TestClasses.php';

use GraphQL\Error\Error;
use GraphQL\Executor\Executor;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

/**
 * Execute: Handles inputs
 * Handles objects and nullability
 */
class VariablesTest extends TestCase
{

    public function testUsingInlineStructs() : void
    {
        // executes with complex input:
        $result = $this->executeQuery('
        {
          fieldWithObjectInput(input: {a: "foo", b: ["bar"], c: "baz"})
        }
        ');

        $expected = [
            'data' => [
            'fieldWithObjectInput' => '{"a":"foo","b":["bar"],"c":"baz"}'
          ]
        ];
        $this->assertEquals($expected, $result->toArray());

        // properly parses single value to list:
        $result = $this->executeQuery('
        {
          fieldWithObjectInput(input: {a: "foo", b: "bar", c: "baz"})
        }
        ');
        $expected = ['data' => ['fieldWithObjectInput' => '{"a":"foo","b":["bar"],"c":"baz"}']];

        $this->assertEquals($expected, $result->toArray());

        // properly parses null value to null
        $result = $this->executeQuery('
        {
          fieldWithObjectInput(input: {a: null, b: null, c: "C", d: null})
        }
        ');
        $expected = ['data' => ['fieldWithObjectInput' => '{"a":null,"b":null,"c":"C","d":null}']];

        $this->assertEquals($expected, $result->toArray());

        // properly parses null value in list
        $result = $this->executeQuery('
        {
          fieldWithObjectInput(input: {b: ["A",null,"C"], c: "C"})
        }
        ');
        $expected = ['data' => ['fieldWithObjectInput' => '{"b":["A",null,"C"],"c":"C"}']];

        $this->assertEquals($expected, $result->toArray());

        // does not use incorrect value
        $result = $this->executeQuery('
        {
          fieldWithObjectInput(input: ["foo", "bar", "baz"])
        }
        ');

        $expected = [
            'data' => ['fieldWithObjectInput' => null],
            'errors' => [[
                'message' => 'Argument "input" has invalid value ["foo", "bar", "baz"].',
                'path' => ['fieldWithObjectInput'],
                'locations' => [['line' => 3, 'column' => 39]]
            ]]
        ];
        $this->assertArraySubset($expected, $result->toArray());

        // properly runs parseLiteral on complex scalar types
        $result = $this->executeQuery('
        {
          fieldWithObjectInput(input: {c: "foo", d: "SerializedValue"})
        }
        ');
        $this->assertEquals(
            ['data' => ['fieldWithObjectInput' => '{"c":"foo","d":"DeserializedValue"}']],
            $result->toArray()
        );
    }

    public function testUsingVariables() : void
    {
        $doc = '
            query q($input:TestInputObject) {
              fieldWithObjectInput(input: $input)
            }
        ';

        // executes with complex input:
        $params = ['input' => ['a' => 'foo', 'b' => ['bar'], 'c' => 'baz']];
        $result = $this->executeQuery($doc, $params);

        $this->assertEquals(
            ['data' => ['fieldWithObjectInput' => '{"a":"foo","b":["bar"],"c":"baz"}']],
            $result->toArray()
        );

        // uses default value when not provided:
        $result = $this->executeQuery('
          query ($input: TestInputObject = {a: "foo", b: ["bar"], c: "baz"}) {
            fieldWithObjectInput(input: $input)
          }
        ');

        $expected = [
            'data' => ['fieldWithObjectInput' => '{"a":"foo","b":["bar"],"c":"baz"}']
        ];
        $this->assertEquals($expected, $result->toArray());

        // properly parses single value to list:
        $params = ['input' => ['a' => 'foo', 'b' => 'bar', 'c' => 'baz']];
        $result = $this->executeQuery($doc, $params);
        $this->assertEquals(
            ['data' => ['fieldWithObjectInput' => '{"a":"foo","b":["bar"],"c":"baz"}']],
            $result->toArray()
        );

        // executes with complex scalar input:
        $params = [ 'input' => [ 'c' => 'foo', 'd' => 'SerializedValue' ] ];
        $result = $this->executeQuery($doc, $params);
        $expected = [
          'data' => [
            'fieldWithObjectInput' => '{"c":"foo","d":"DeserializedValue"}'
          ]
        ];
        $this->assertEquals($expected, $result->toArray());

        // errors on null for nested non-null:
        $params = ['input' => ['a' => 'foo', 'b' => 'bar', 'c' => null]];
        $result = $this->executeQuery($doc, $params);
        $expected = [
            'errors' => [
                [
                    'message' =>
                        'Variable "$input" got invalid value ' .
                        '{"a":"foo","b":"bar","c":null}; ' .
                        'Expected non-nullable type String! not to be null at value.c.',
                    'locations' => [['line' => 2, 'column' => 21]],
                    'category' => 'graphql'
                ]
            ]
        ];

        $this->assertEquals($expected, $result->toArray());

        // errors on incorrect type:
        $params = [ 'input' => 'foo bar' ];
        $result = $this->executeQuery($doc, $params);
        $expected = [
            'errors' => [
                [
                    'message' =>
                        'Variable "$input" got invalid value "foo bar"; ' .
                        'Expected type TestInputObject to be an object.',
                    'locations' => [ [ 'line' => 2, 'column' => 21 ] ],
                    'category' => 'graphql',
                ]
            ]
        ];
        $this->assertEquals($expected, $result->toArray());

        // errors on omission of nested non-null:
        $params = ['input' => ['a' => 'foo', 'b' => 'bar']];

        $result = $this->executeQuery($doc, $params);
        $expected = [
            'errors' => [
                [
                    'message' =>
                        'Variable "$input" got invalid value {"a":"foo","b":"bar"}; '.
                        'Field value.c of required type String! was not provided.',
                    'locations' => [['line' => 2, 'column' => 21]],
                    'category' => 'graphql',
                ]
            ]
        ];
        $this->assertEquals($expected, $result->toArray());

        // errors on deep nested errors and with many errors
        $nestedDoc = '
          query q($input: TestNestedInputObject) {
            fieldWithNestedObjectInput(input: $input)
          }
        ';
        $params = [ 'input' => [ 'na' => [ 'a' => 'foo' ] ] ];

        $result = $this->executeQuery($nestedDoc, $params);
        $expected = [
            'errors' => [
                [
                    'message' =>
                        'Variable "$input" got invalid value {"na":{"a":"foo"}}; ' .
                        'Field value.na.c of required type String! was not provided.',
                    'locations' => [['line' => 2, 'column' => 19]],
                    'category' => 'graphql',
                ],
                [
                    'message' =>
                        'Variable "$input" got invalid value {"na":{"a":"foo"}}; ' .
                        'Field value.nb of required type String! was not provided.',
                    'locations' => [['line' => 2, 'column' => 19]],
                    'category' => 'graphql',
                ]
            ]
        ];
        $this->assertEquals($expected, $result->toArray());


        // errors on addition of unknown input field
        $params = ['input' => [ 'a' => 'foo', 'b' => 'bar', 'c' => 'baz', 'extra' => 'dog' ]];
        $result = $this->executeQuery($doc, $params);
        $expected = [
            'errors' => [
                [
                    'message' =>
                        'Variable "$input" got invalid value ' .
                        '{"a":"foo","b":"bar","c":"baz","extra":"dog"}; ' .
                        'Field "extra" is not defined by type TestInputObject.',
                    'locations' => [['line' => 2, 'column' => 21]],
                    'category' => 'graphql',
                ]
            ]
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    // Describe: Handles nullable scalars

    /**
     * @see it('allows nullable inputs to be omitted')
     */
    public function testAllowsNullableInputsToBeOmitted() : void
    {
        $result = $this->executeQuery('
      {
        fieldWithNullableStringInput
      }
        ');
        $expected = [
            'data' => ['fieldWithNullableStringInput' => null]
        ];

        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows nullable inputs to be omitted in a variable')
     */
    public function testAllowsNullableInputsToBeOmittedInAVariable() : void
    {
        $result = $this->executeQuery('
      query SetsNullable($value: String) {
        fieldWithNullableStringInput(input: $value)
      }
        ');
        $expected = ['data' => ['fieldWithNullableStringInput' => null]];

        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows nullable inputs to be omitted in an unlisted variable')
     */
    public function testAllowsNullableInputsToBeOmittedInAnUnlistedVariable() : void
    {
        $result = $this->executeQuery('
      query SetsNullable {
        fieldWithNullableStringInput(input: $value)
      }
      ');
        $expected = ['data' => ['fieldWithNullableStringInput' => null]];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows nullable inputs to be set to null in a variable')
     */
    public function testAllowsNullableInputsToBeSetToNullInAVariable() : void
    {
        $result = $this->executeQuery('
      query SetsNullable($value: String) {
        fieldWithNullableStringInput(input: $value)
      }
        ');
        $expected = ['data' => ['fieldWithNullableStringInput' => null]];

        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows nullable inputs to be set to a value in a variable')
     */
    public function testAllowsNullableInputsToBeSetToAValueInAVariable() : void
    {
        $doc = '
      query SetsNullable($value: String) {
        fieldWithNullableStringInput(input: $value)
      }
        ';
        $result = $this->executeQuery($doc, ['value' => 'a']);
        $expected = ['data' => ['fieldWithNullableStringInput' => '"a"']];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows nullable inputs to be set to a value directly')
     */
    public function testAllowsNullableInputsToBeSetToAValueDirectly() : void
    {
        $result = $this->executeQuery('
      {
        fieldWithNullableStringInput(input: "a")
      }
        ');
        $expected = ['data' => ['fieldWithNullableStringInput' => '"a"']];
        $this->assertEquals($expected, $result->toArray());
    }


    // Describe: Handles non-nullable scalars

    /**
     * @see it('allows non-nullable inputs to be omitted given a default')
     */
    public function testAllowsNonNullableInputsToBeOmittedGivenADefault() : void
    {
        $result = $this->executeQuery('
        query SetsNonNullable($value: String = "default") {
          fieldWithNonNullableStringInput(input: $value)
        }
        ');
        $expected = [
            'data' => ['fieldWithNonNullableStringInput' => '"default"']
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('does not allow non-nullable inputs to be omitted in a variable')
     */
    public function testDoesntAllowNonNullableInputsToBeOmittedInAVariable() : void
    {
        $result = $this->executeQuery('
        query SetsNonNullable($value: String!) {
          fieldWithNonNullableStringInput(input: $value)
        }
        ');

        $expected = [
            'errors' => [
                [
                    'message' => 'Variable "$value" of required type "String!" was not provided.',
                    'locations' => [['line' => 2, 'column' => 31]],
                    'category' => 'graphql'
                ]
            ]
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('does not allow non-nullable inputs to be set to null in a variable')
     */
    public function testDoesNotAllowNonNullableInputsToBeSetToNullInAVariable() : void
    {
        $doc = '
        query SetsNonNullable($value: String!) {
          fieldWithNonNullableStringInput(input: $value)
        }
        ';
        $result = $this->executeQuery($doc, ['value' => null]);
        $expected = [
            'errors' => [
                [
                    'message' =>
                        'Variable "$value" got invalid value null; ' .
                        'Expected non-nullable type String! not to be null.',
                    'locations' => [['line' => 2, 'column' => 31]],
                    'category' => 'graphql',
                ]
            ]
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows non-nullable inputs to be set to a value in a variable')
     */
    public function testAllowsNonNullableInputsToBeSetToAValueInAVariable() : void
    {
        $doc = '
        query SetsNonNullable($value: String!) {
          fieldWithNonNullableStringInput(input: $value)
        }
        ';
        $result = $this->executeQuery($doc, ['value' => 'a']);
        $expected = ['data' => ['fieldWithNonNullableStringInput' => '"a"']];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows non-nullable inputs to be set to a value directly')
     */
    public function testAllowsNonNullableInputsToBeSetToAValueDirectly() : void
    {
        $result = $this->executeQuery('
      {
        fieldWithNonNullableStringInput(input: "a")
      }
        ');
        $expected = ['data' => ['fieldWithNonNullableStringInput' => '"a"']];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('reports error for missing non-nullable inputs')
     */
    public function testReportsErrorForMissingNonNullableInputs() : void
    {
        $result = $this->executeQuery('
      {
        fieldWithNonNullableStringInput
      }
        ');
        $expected = [
            'data' => ['fieldWithNonNullableStringInput' => null],
            'errors' => [[
                'message' => 'Argument "input" of required type "String!" was not provided.',
                'locations' => [ [ 'line' => 3, 'column' => 9 ] ],
                'path' => [ 'fieldWithNonNullableStringInput' ],
                'category' => 'graphql',
            ]]
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('reports error for array passed into string input')
     */
    public function testReportsErrorForArrayPassedIntoStringInput() : void
    {

        $doc = '
        query SetsNonNullable($value: String!) {
          fieldWithNonNullableStringInput(input: $value)
        }
        ';
        $variables = ['value' => [1, 2, 3]];
        $result = $this->executeQuery($doc, $variables);

        $expected = [
            'errors' => [[
                'message' =>
                    'Variable "$value" got invalid value [1,2,3]; Expected type ' .
                    'String; String cannot represent an array value: [1,2,3]',
                'category' => 'graphql',
                'locations' => [
                    ['line' => 2, 'column' => 31]
                ]
            ]]
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('serializing an array via GraphQLString throws TypeError')
     */
    public function testSerializingAnArrayViaGraphQLStringThrowsTypeError() : void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('String cannot represent non scalar value: [1,2,3]');
        Type::string()->serialize([1, 2, 3]);
    }

    /**
     * @see it('reports error for non-provided variables for non-nullable inputs')
     */
    public function testReportsErrorForNonProvidedVariablesForNonNullableInputs() : void
    {
        // Note: this test would typically fail validation before encountering
        // this execution error, however for queries which previously validated
        // and are being run against a new schema which have introduced a breaking
        // change to make a formerly non-required argument required, this asserts
        // failure before allowing the underlying code to receive a non-null value.
        $result = $this->executeQuery('
      {
        fieldWithNonNullableStringInput(input: $foo)
      }
        ');
        $expected = [
            'data' => ['fieldWithNonNullableStringInput' => null],
            'errors' => [[
                'message' =>
                    'Argument "input" of required type "String!" was provided the ' .
                    'variable "$foo" which was not provided a runtime value.',
                'locations' => [['line' => 3, 'column' => 48]],
                'path' => ['fieldWithNonNullableStringInput'],
                'category' => 'graphql',
            ]]
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    // Describe: Handles lists and nullability

    /**
     * @see it('allows lists to be null')
     */
    public function testAllowsListsToBeNull() : void
    {
        $doc = '
        query q($input:[String]) {
          list(input: $input)
        }
        ';
        $result = $this->executeQuery($doc, ['input' => null]);
        $expected = ['data' => ['list' => null]];

        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows lists to contain values')
     */
    public function testAllowsListsToContainValues() : void
    {
        $doc = '
        query q($input:[String]) {
          list(input: $input)
        }
        ';
        $result = $this->executeQuery($doc, ['input' => ['A']]);
        $expected = ['data' => ['list' => '["A"]']];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows lists to contain null')
     */
    public function testAllowsListsToContainNull() : void
    {
        $doc = '
        query q($input:[String]) {
          list(input: $input)
        }
        ';
        $result = $this->executeQuery($doc, ['input' => ['A',null,'B']]);
        $expected = ['data' => ['list' => '["A",null,"B"]']];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('does not allow non-null lists to be null')
     */
    public function testDoesNotAllowNonNullListsToBeNull() : void
    {
        $doc = '
        query q($input:[String]!) {
          nnList(input: $input)
        }
        ';
        $result = $this->executeQuery($doc, ['input' => null]);
        $expected = [
            'errors' => [
                [
                    'message' =>
                        'Variable "$input" got invalid value null; ' .
                        'Expected non-nullable type [String]! not to be null.',
                    'locations' => [['line' => 2, 'column' => 17]],
                    'category' => 'graphql',
                ]
            ]
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows non-null lists to contain values')
     */
    public function testAllowsNonNullListsToContainValues() : void
    {
        $doc = '
        query q($input:[String]!) {
          nnList(input: $input)
        }
        ';
        $result = $this->executeQuery($doc, ['input' => ['A']]);
        $expected = ['data' => ['nnList' => '["A"]']];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows non-null lists to contain null')
     */
    public function testAllowsNonNullListsToContainNull() : void
    {
        $doc = '
        query q($input:[String]!) {
          nnList(input: $input)
        }
        ';
        $result = $this->executeQuery($doc, ['input' => ['A',null,'B']]);
        $expected = ['data' => ['nnList' => '["A",null,"B"]']];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows lists of non-nulls to be null')
     */
    public function testAllowsListsOfNonNullsToBeNull() : void
    {
        $doc = '
        query q($input:[String!]) {
          listNN(input: $input)
        }
        ';
        $result = $this->executeQuery($doc, ['input' => null]);
        $expected = ['data' => ['listNN' => null]];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows lists of non-nulls to contain values')
     */
    public function testAllowsListsOfNonNullsToContainValues() : void
    {
        $doc = '
        query q($input:[String!]) {
          listNN(input: $input)
        }
        ';
        $result = $this->executeQuery($doc, ['input' => ['A']]);
        $expected = ['data' => ['listNN' => '["A"]']];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('does not allow lists of non-nulls to contain null')
     */
    public function testDoesNotAllowListsOfNonNullsToContainNull() : void
    {
        $doc = '
        query q($input:[String!]) {
          listNN(input: $input)
        }
        ';
        $result = $this->executeQuery($doc, ['input' => ['A', null, 'B']]);
        $expected = [
            'errors' => [
                [
                    'message' =>
                        'Variable "$input" got invalid value ["A",null,"B"]; ' .
                        'Expected non-nullable type String! not to be null at value[1].',
                    'locations' => [ ['line' => 2, 'column' => 17] ],
                    'category' => 'graphql',
                ]
            ]
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('does not allow non-null lists of non-nulls to be null')
     */
    public function testDoesNotAllowNonNullListsOfNonNullsToBeNull() : void
    {
        $doc = '
        query q($input:[String!]!) {
          nnListNN(input: $input)
        }
        ';
        $result = $this->executeQuery($doc, ['input' => null]);
        $expected = [
            'errors' => [
                [
                    'message' =>
                        'Variable "$input" got invalid value null; ' .
                        'Expected non-nullable type [String!]! not to be null.',
                    'locations' => [ ['line' => 2, 'column' => 17] ],
                    'category' => 'graphql',
                ]
            ]
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('allows non-null lists of non-nulls to contain values')
     */
    public function testAllowsNonNullListsOfNonNullsToContainValues() : void
    {
        $doc = '
        query q($input:[String!]!) {
          nnListNN(input: $input)
        }
        ';
        $result = $this->executeQuery($doc, ['input' => ['A']]);
        $expected = ['data' => ['nnListNN' => '["A"]']];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('does not allow non-null lists of non-nulls to contain null')
     */
    public function testDoesNotAllowNonNullListsOfNonNullsToContainNull() : void
    {
        $doc = '
        query q($input:[String!]!) {
          nnListNN(input: $input)
        }
        ';
        $result = $this->executeQuery($doc, ['input' => ['A', null, 'B']]);
        $expected = [
            'errors' => [
                [
                    'message' =>
                        'Variable "$input" got invalid value ["A",null,"B"]; ' .
                        'Expected non-nullable type String! not to be null at value[1].',
                    'locations' => [ ['line' => 2, 'column' => 17] ],
                    'category' => 'graphql',
                ]
            ]
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('does not allow invalid types to be used as values')
     */
    public function testDoesNotAllowInvalidTypesToBeUsedAsValues() : void
    {
        $doc = '
        query q($input: TestType!) {
          fieldWithObjectInput(input: $input)
        }
        ';
        $vars = [ 'input' => [ 'list' => [ 'A', 'B' ] ] ];
        $result = $this->executeQuery($doc, $vars);
        $expected = [
            'errors' => [
                [
                    'message' =>
                        'Variable "$input" expected value of type "TestType!" which cannot ' .
                        'be used as an input type.',
                    'locations' => [['line' => 2, 'column' => 25]],
                    'category' => 'graphql',
                ]
            ]
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    /**
     * @see it('does not allow unknown types to be used as values')
     */
    public function testDoesNotAllowUnknownTypesToBeUsedAsValues() : void
    {
        $doc = '
        query q($input: UnknownType!) {
          fieldWithObjectInput(input: $input)
        }
        ';
        $vars = ['input' => 'whoknows'];

        $result = $this->executeQuery($doc, $vars);
        $expected = [
            'errors' => [
                [
                    'message' =>
                        'Variable "$input" expected value of type "UnknownType!" which ' .
                        'cannot be used as an input type.',
                    'locations' => [['line' => 2, 'column' => 25]],
                    'category' => 'graphql',
                ]
            ]
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    // Describe: Execute: Uses argument default values
    /**
     * @see it('when no argument provided')
     */
    public function testWhenNoArgumentProvided() : void
    {
        $result = $this->executeQuery('{
        fieldWithDefaultArgumentValue
        }');

        $this->assertEquals(
            ['data' => ['fieldWithDefaultArgumentValue' => '"Hello World"']],
            $result->toArray()
        );
    }

    /**
     * @see it('when omitted variable provided')
     */
    public function testWhenOmittedVariableProvided() : void
    {
        $result = $this->executeQuery('query optionalVariable($optional: String) {
            fieldWithDefaultArgumentValue(input: $optional)
        }');

        $this->assertEquals(
            ['data' => ['fieldWithDefaultArgumentValue' => '"Hello World"']],
            $result->toArray()
        );
    }

    /**
     * @see it('not when argument cannot be coerced')
     */
    public function testNotWhenArgumentCannotBeCoerced() : void
    {
        $result = $this->executeQuery('{
            fieldWithDefaultArgumentValue(input: WRONG_TYPE)
        }');

        $expected = [
            'data' => ['fieldWithDefaultArgumentValue' => null],
            'errors' => [[
                'message' =>
                    'Argument "input" has invalid value WRONG_TYPE.',
                'locations' => [ [ 'line' => 2, 'column' => 50 ] ],
                'path' => [ 'fieldWithDefaultArgumentValue' ],
                'category' => 'graphql',
            ]]
        ];

        $this->assertEquals($expected, $result->toArray());
    }


    public function schema()
    {
        $ComplexScalarType = ComplexScalar::create();

        $TestInputObject = new InputObjectType([
            'name' => 'TestInputObject',
            'fields' => [
                'a' => ['type' => Type::string()],
                'b' => ['type' => Type::listOf(Type::string())],
                'c' => ['type' => Type::nonNull(Type::string())],
                'd' => ['type' => $ComplexScalarType],
            ]
        ]);

        $TestNestedInputObject = new InputObjectType([
            'name' => 'TestNestedInputObject',
            'fields' => [
                'na' => [ 'type' => Type::nonNull($TestInputObject) ],
                'nb' => [ 'type' => Type::nonNull(Type::string()) ],
            ],
        ]);

        $TestType = new ObjectType([
            'name' => 'TestType',
            'fields' => [
                'fieldWithObjectInput' => $this->fieldWithInputArg(['type' => $TestInputObject]),
                'fieldWithNullableStringInput' => $this->fieldWithInputArg(['type' => Type::string()]),
                'fieldWithNonNullableStringInput' => $this->fieldWithInputArg(['type' => Type::nonNull(Type::string())]),
                'fieldWithDefaultArgumentValue' => $this->fieldWithInputArg([
                    'type' => Type::string(),
                    'defaultValue' => 'Hello World',
                ]),
                'fieldWithNestedInputObject' => $this->fieldWithInputArg([
                    'type' => $TestNestedInputObject,
                    'defaultValue' => 'Hello World'
                ]),
                'list' => $this->fieldWithInputArg(['type' => Type::listOf(Type::string())]),
                'nnList' => $this->fieldWithInputArg(['type' => Type::nonNull(Type::listOf(Type::string()))]),
                'listNN' => $this->fieldWithInputArg(['type' => Type::listOf(Type::nonNull(Type::string()))]),
                'nnListNN' => $this->fieldWithInputArg(['type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string())))]),
            ]
        ]);

        $schema = new Schema(['query' => $TestType]);
        return $schema;
    }

    private function fieldWithInputArg($inputArg)
    {
        return [
            'type' => Type::string(),
            'args' => ['input' => $inputArg],
            'resolve' => function ($_, $args) {
                if (isset($args['input'])) {
                    return json_encode($args['input']);
                }
                return null;
            },
        ];
    }

    private function executeQuery($query, $variableValues = null)
    {
        $document = Parser::parse($query);
        return Executor::execute($this->schema(), $document, null, null, $variableValues);
    }
}
