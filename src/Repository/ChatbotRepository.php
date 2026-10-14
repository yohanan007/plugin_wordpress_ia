<?php

namespace WPIA\Repository;

class ChatbotRepository extends AbstractRepository {
	protected string $table_suffix = 'chatbot';

	protected array $columns = array(
		'designation'  => '%s',
		'description'  => '%s',
		'compte_api_id' => '%d',
		'modele'        => '%s',
		'system_prompt' => '%s',
		'max_tokens'    => '%d',
		'temperature'   => '%f',
		'actif'         => '%d',
	);
}
