<?php

namespace WPIA\Repository;

class MessageRepository extends AbstractRepository {
	protected string $table_suffix = 'message';

	protected array $columns = array(
		'conversation_id' => '%d',
		'role'            => '%s',
		'contenu'         => '%s',
		'tokens'          => '%d',
		'date_creation'   => '%s',
	);
}
