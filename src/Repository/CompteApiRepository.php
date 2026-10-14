<?php

namespace WPIA\Repository;

class CompteApiRepository extends AbstractRepository {
	protected string $table_suffix = 'compte_api';

	protected array $columns = array(
		'fournisseur_id' => '%d',
		'api_key'        => '%s',
		'commentaire'    => '%s',
		'actif'          => '%d',
	);
}
