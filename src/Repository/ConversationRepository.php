<?php

namespace WPIA\Repository;

class ConversationRepository extends AbstractRepository {
	protected string $table_suffix = 'conversation';

	protected array $columns = array(
		'chatbot_id'             => '%d',
		'session_id'             => '%s',
		'ip_hash'                => '%s',
		'nb_messages'            => '%d',
		'date_creation'          => '%s',
		'date_derniere_activite' => '%s',
	);
}
