<?php

namespace WPIA\Repository;

class LimiteUtilisationRepository extends AbstractRepository {
	protected string $table_suffix = 'limite_utilisation';

	protected array $columns = array(
		'chatbot_id'       => '%d',
		'session_id'       => '%s',
		'ip_hash'          => '%s',
		'nb_messages_jour' => '%d',
		'nb_tokens_jour'   => '%d',
		'date_maj'         => '%s',
	);
}
