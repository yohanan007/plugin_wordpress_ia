<?php

namespace WPIA\Repository;

class DocumentRepository extends AbstractRepository {
	protected string $table_suffix = 'document';

	protected array $columns = array(
		'designation'   => '%s',
		'description'   => '%s',
		'fichier'       => '%s',
		'hash'          => '%s',
		'taille_tokens' => '%d',
		'actif'         => '%d',
	);
}
