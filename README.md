# LittlePAPI
Little Php API is a little ORM/API maker written in PHP  
(Papi mean grandpa in French)

## Why
For a project, I had to use PHP (not my favorite language :o), but I didn't wanted to use heavy stuff like Doctrine. At the begining, my needs was small, so I created my own little thing.  
Now it's a *little* bigger, and it might be used

## How to use
### Installation
Juste include LittlePAPI.php :)

### Map object & db
Create an class witch extends LittlePAPI.

Your class need few `protected static` attributes:
	+ `tableName` : string, the name of the table in the DB 
	+ `keys` : array of string, fields name in the DB

And that's all !

### Define getters/setters
Let's suppose you have a `mail` field, and that you want to define a setter for this field.
Declare a `setterMail` function, taking in first parameter the value to set

Same for getters, just call it `getterMail`

### Define constraints for accessors
Define functions :
	+ `constraintSetKEY`
	+ `constraintGetKEY`

### Transform value after get
define function `transformGetKey`

### Other
`constraintFetchAll`
`constraintSerialize`

### Need to link two objects
For example, let Mail and User two object, you'd like to be able to list mails of an user.
The protected static $relationsDescription attribute can help you.
It's an array of "relations".
```
	protected static $relationsDescription = array(
		array(
			'name' => 'mails', // make $anUser->get("mails") working
			'tableName' => 'RelMailsUsers',	//table RelMailsUsers contain idUser & idMail
			'externId' => 'idMail',
			'internId' => 'idUser',
			'classObject' => 'Mail'	// Mail object represent the Mail concept
		)
	)
```

Over way (better) : `LittlePAPI::RegisterRelation(...)` (TODO)

### Create row in DB
Let Mail an object.
```
	$aMail = Mail::create([
			"subejct" => "Newsletter number xx : xxxxxx",
			"content" => "Hello,\n ....",

			 ...

			"rowName" => "content"
		]);
```

### Fetch an object
```
$aMail = new Mail(remplaceWithIdOfTheMail);
```

### Fetch all objects
```
Mail::_fetchAll([add clause SQL]);
```

### Fetch all via API
If you have an Mail class, just create a Mails class
```
class Mails {
	public static function getAll(){
		return Mail::_fetchAll();
	}
	public static function getAnykey(){
		[your code]
	}
}
```

the API will link (api url)?/mail/anykey and (api url)?/mail/anykey



# doc not complete
If you're interested, send me a mail at lucas@franceschino.fr :)

# Bugs
I didn't actually tested everything, so it might be bugged