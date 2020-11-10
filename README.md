# DB Manager
PDO wrapper to simplify db queries.
It provides a simple way to create, retrieve, update & delete records.


[![Version](https://badge.fury.io/gh/h2lsoft%2Fdb-manager.svg)](https://badge.fury.io/gh/h2lsoft%2Fdb-manager)



## Requirements

- php >= 7.3
- php PDO extension


## Installation

Install directly via [Composer](https://getcomposer.org):
```bash
$ composer require h2lsoft/db-manager
```

## Basic Usage

```php
use \h2lsoft\DBManager;

$DBM = new DBManager\DBManager(); // soft mode is activated by default
$DBM->connect('mysql', 'localhost', 'root', '', 'mydatabase');

// execute simple query with binding
$sql = "SELECT Name, SurfaceArea FROM Country WHERE Continent = :Continent AND deleted = 'NO' ORDER BY SurfaceArea DESC LIMIT 3";
$results = $DBM->query($sql, [':Continent' =>  'Asia'])->fetchAll();

// or use short version
$sql = $DBM->select("Name, SurfaceArea")
           ->from('Country')
           ->where("Continent = :Continent")
           ->orderBy('SurfaceArea DESC')
           ->limit(3)
           ->getSQL();
$results = $DBM->query($sql, [':Continent' =>  'Asia'])->fetchAll();

// or imbricated version
$results = $DBM->select("Name, SurfaceArea")
                      ->from('Country')
                      ->where("Continent = :Continent")
                      ->orderBy('SurfaceArea DESC')
                      ->limit(3)
                      ->executeSql([':Continent' =>  'Asia'])
                            ->fetchAll();


// insert
$values = [];
$values['Name'] = "Agatha Christies";
$values['Birthdate'] = "1890-10-15";

$ID = $DBM->table('Author')->insert($values);


// update
$values = [];
$values['Name'] = "Agatha Christies";
$affected_rows = $DBM->table('Author')->update($values, $ID); # you can put direct ID or you can use where clause

// delete
$affected_rows = $DBM->table('Author')->delete(["ID = ?", $ID]);


```

## Soft Mode

Soft mode is activated by default, it allow to automatic timestamp and author on each record for operation like : `Insert`, `Update`, `Delete`.
Soft mode is optional but recommended, you can disable it in constructor or you can use $DBM->SoftMode(0);

Soft mode allows you to keep data safe by turn flag field `deleted` to `yes` and no trash your record physically.
It is useful to retrieve your data in case of accidentally delete rows.

You can use magic method `$DBM->table('my_table')->addSoftModeColumns()` this will add automatically soft mode columns :

- `deleted` (enum => yes, no)
- `created_at` (datetime)
- `created_by` (varchar)
- `updated_at` (datetime)
- `updated_by` (varchar)
- `deleted_at` (datetime)
- `deleted_by` (varchar)


## Pagination component

```php
$sql = $DBM->select("*")
           ->from('Country')
           ->where("Continent = :Continent")
           ->getSQL();

$params = [':Continent' => 'Asia'];

$current_page = 1;

// return a complete array paginate
$pager = $DBM->paginate($sql, $params, $current_page, 20);
```

```
[
    [total] => 51,
    [per_page] => 20,
    [last_page] => 3,
    [current_page] => 1,
    [from] => 1,
    [to] => 20,
    [page_start] => 1,
    [page_end] => 3,
    [data] => [
                        ....
              ]         
]
```

## Useful methods

```php

// get a record by ID, you can use multiple ID by array
$record = $DBM->table('Country')->getByID(10);

//  multiple ID
$records = $DBM->table('Country')->getByID([12, 10, 55]);

```



## License

MIT. See full [license](LICENSE).
