<?php declare(strict_types=1);

namespace GraphQL\Examples\Blog\Type;

use GraphQL\Examples\Blog\Data\Comment;
use GraphQL\Examples\Blog\Data\DataSource;
use GraphQL\Examples\Blog\Data\User;
use GraphQL\Examples\Blog\Type\Field\HtmlField;
use GraphQL\Examples\Blog\Types;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\ObjectType;

class CommentType extends ObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'Comment',
            'fields' => static fn (): array => [
                'id' => Types::id(),
                'author' => [
                    'type' => Types::user(),
                    'resolve' => static fn (Comment $comment): ?User => $comment->isAnonymous
                            ? null
                            : DataSource::findUser($comment->authorId),
                ],
                'parent' => [
                    'type' => Types::comment(),
                    'resolve' => static fn (Comment $comment): ?Comment =>
						$comment->parentId !== null
						? DataSource::findComment($comment->parentId)
						: null
                ],
                'isAnonymous' => ['type' => Types::boolean(), 'resolve' => static fn (Comment $comment): bool => $comment->isAnonymous],
                'replies' => [
                    'type' => new ListOfType(Types::comment()),
                    'args' => [
                        'after' => Types::int(),
                        'limit' => [
                            'type' => Types::int(),
                            'defaultValue' => 5,
                        ],
                    ],
                    'resolve' => function (Comment $comment, array $args): array {
                        $args += ['after' => null];

                        return DataSource::findReplies($comment->id, $args['limit'], $args['after']);
                    },
                ],
                'totalReplyCount' => [
                    'type' => Types::int(),
                    'resolve' => function (Comment $comment): int {
                        return DataSource::countReplies($comment->id);
                    },
                ],

                'body' => HtmlField::build([
                    'resolve' => static fn (Comment $comment): string => $comment->body,
                ]),
            ],
        ]);
    }
}
