<?php

namespace WPIA\Repository;

class FournisseurIARepository extends AbstractRepository {
	protected string $table_suffix = 'fournisseur_ia';

	protected array $columns = array(
		'designation' => '%s',
		'url_api'     => '%s',
		'actif'       => '%d',
	);
}
