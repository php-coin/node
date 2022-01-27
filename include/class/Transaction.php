<?php

class Transaction
{


	public $val;
	public $fee;
	public $dst;
	public $msg;
	public $type;
	public $publicKey;
	public $date;
	public $signature;
	public $height;
	public $peer;
	public $id;

	public $src;

	public function __construct($publicKey=null,$dst=null,$val=null,$type=null,$date = null,$msg = null)
	{
		$this->val = $val;
		$this->fee = 0;
		$this->dst = $dst;
		$this->msg = $msg;
		$this->type = $type;
		$this->publicKey = $publicKey;
		$this->date = empty($date) ? time() : $date;
		$this->src = Account::getAddress($this->publicKey);
	}


	// reverse and remove all transactions from a block
    public static function reverse($block)
    {
        global $db;
        
        $r = $db->run("SELECT * FROM transactions WHERE block=:block ORDER by `type` DESC", [":block" => $block]);
        foreach ($r as $x) {
        	$tx = Transaction::getFromDbRecord($x);
            _log("Reversing transaction {$tx->id}", 3);
            if (empty($tx->src)) {
	            $tx->src = Account::getAddress($tx->publicKey);
            }

	        $type = $tx->type;
	        $res = true;
	        if($type == TX_TYPE_REWARD) {
		        $res = $res && Account::addBalance($tx->dst, $tx->val*(-1));
	        }
			if ($type == TX_TYPE_SEND) {
		        $res = $res && Account::addBalance($tx->dst, $tx->val*(-1));
		        $res = $res && Account::addBalance($tx->src, $tx->val);
		        $tx->add_mempool();
	        }

	        if ($type == TX_TYPE_MN_CREATE) {
		        $res = $res && Account::addBalance($tx->dst, $tx->val*(-1));
		        $res = $res && Account::addBalance($tx->src, $tx->val);
		        $tx->add_mempool();
		        if($res === false) {
			        _log("Update balance for reverse transaction failed");
			        return false;
		        }
				$publicKey = Account::publicKey($tx->dst);
				$res = Masternode::delete($publicKey);
				if(!$res) {
					_log("Error deleting masternode");
					return false;
				}
	        }

	        if ($type == TX_TYPE_MN_REMOVE) {
		        $res = $res && Account::addBalance($tx->dst, $tx->val*(-1));
		        $res = $res && Account::addBalance($tx->src, $tx->val);
		        $tx->add_mempool();
		        if($res === false) {
			        _log("Update balance for reverse transaction failed");
			        return false;
		        }
		        $height = Masternode::getMnCreateHeight($tx->publicKey);
				if(!$height) {
					_log("Can not find mn create tx height");
					return false;
				}
				$res = Masternode::create($tx->publicKey, $height);
				if(!$res) {
					_log("Can not reverse create masternode");
					return false;
				}
	        }


	        if($res === false) {
		        _log("Update balance for reverse transaction failed");
		        return false;
	        }
            $res = $db->run("DELETE FROM transactions WHERE id=:id", [":id" => $tx->id]);
            if ($res != 1) {
                _log("Delete transaction failed");
                return false;
            }
        }
    }

    // clears the mempool
    public static function clean_mempool()
    {
        global $db;
        $current = Block::current();
        $height = $current['height'];
        $limit = $height - 1000;
		Mempool::deleteToeight($limit);
    }

    public static function empty_mempool()
    {
        Mempool::empty_mempool();
    }

    public static function getFromDbRecord($x) {
	    $trans = new Transaction($x['public_key'],$x['dst'],$x['val'],intval($x['type']),intval($x['date']),$x['message']);
	    $trans->id = $x['id'];
	    $trans->src = $x['src'];
	    $trans->fee = $x['fee'];
	    $trans->signature = $x['signature'];
	    $trans->height = $x['height'];
	    $trans->peer = $x['peer'];
	    return $trans;
    }

	public static function getFromArray($x) {
		$trans = new Transaction($x['public_key'],$x['dst'],floatval($x['val']),$x['type'],$x['date'],$x['message']);
		$trans->id = $x['id'];
		$trans->src = $x['src'];
		$trans->fee = floatval($x['fee']);
		$trans->signature = $x['signature'];
		return $trans;
	}

    public function toArray() {
	    $trans = [
		    "id"         => $this->id,
		    "dst"        => $this->dst,
		    "src"        => $this->src,
		    "val"        => num($this->val),
		    "fee"        => num($this->fee),
		    "signature"  => $this->signature,
		    "message"    => $this->msg,
		    "type"    => intval($this->type),
		    "date"       => intval($this->date),
		    "public_key" => $this->publicKey,
	    ];
	    ksort($trans);
	    return $trans;
    }

    // returns X  transactions from mempool
    public static function mempool($max, $with_errors=false, $as_mine_data=true)
    {
        global $db;
        $current = Block::current();
        $height = $current['height'] + 1;
	    $r = Mempool::getTxs($height, $max);
        $transactions = [];
		$mncreate = [];
        if (count($r) > 0) {
            $i = 0;
            $balance = [];
            foreach ($r as $x) {
                $trans = self::getFromDbRecord($x);

                if ($i >= $max) {
                    break;
                }

				$tx_error = false;
                if (empty($x['public_key'])) {
	                $tx_error = "Transaction has empty public_key";
					if(!$with_errors) {
                        continue;
					}
                }
                if (empty($x['src'])) {
	                $tx_error = "Transaction has empty src";
	                if(!$with_errors) {
		                continue;
	                }
                }
                if (!$trans->verify($current['height'], $error)) {
	                $tx_error = "Transaction Check Failed: ".$error;
	                if(!$with_errors) {
		                continue;
	                }
                }

                $balance[$x['src']] += $x['val'] + $x['fee'];
                if ($db->single("SELECT COUNT(1) FROM transactions WHERE id=:id", [":id" => $x['id']]) > 0) {
	                $tx_error = "Duplicate transaction";
	                if(!$with_errors) {
		                continue;
	                }
                }

                $res = $db->single(
                    "SELECT COUNT(1) FROM accounts WHERE id=:id AND balance>=:balance",
                    [":id" => $x['src'], ":balance" => $balance[$x['src']]]
                );

                if ($res == 0) {
	                $tx_error = "Not enough funds in balance";
	                if(!$with_errors) {
		                continue;
	                }
                }

                $i++;
				if($tx_error) {
					_log("$x[id] - $tx_error");
				}
	            $tx_arr = $trans->toArray();
				if($tx_error && $with_errors) {
					$tx_arr['error']=$tx_error;
				}
				if(!$as_mine_data) {
					$tx_arr['height']=$trans->height;
					$tx_arr['peer']=$trans->peer;
				}
                $transactions[$x['id']] = $tx_arr;
            }
        }
        // always sort the array
	    if(!$as_mine_data) {
            ksort($transactions);
	    }
        return $transactions;
    }

	// add a new transaction to mempool and lock it with the current height
	public function add_mempool($peer = "")
	{
		global $db;

		$current = Block::current();
		$height = $current['height'];
		$bind = [
			":peer"      => $peer,
			":id"        => $this->id,
			"public_key" => $this->publicKey,
			":height"    => $height,
			":src"       => $this->src,
			":dst"       => $this->dst,
			":val"       => $this->val,
			":fee"       => $this->fee,
			":signature" => $this->signature,
			":type"   => $this->type,
			":date"      => $this->date,
			":message"   => $this->msg,
		];


		$db->run(
			"INSERT into mempool  
			    (peer, id, public_key, height, src, dst, val, fee, signature, type, message, `date`)
			    values (:peer, :id, :public_key, :height, :src, :dst, :val, :fee, :signature, :type, :message, :date)",
			$bind
		);
		return true;
	}

	// add a new transaction to the blockchain
	public function add($block, $height, $bootstrapping = false)
	{
		global $db;

		$public_key = $this->publicKey;
		$address = Account::getAddress($public_key);
		$res = Account::checkAccount($address, $public_key, $block);
		if ($res === false) {
			_log("Error checking account address");
			return false;
		}
		if ($this->type == TX_TYPE_SEND || $this->type == TX_TYPE_MN_CREATE || $this->type == TX_TYPE_MN_REMOVE) {
			$res = Account::checkAccount($this->dst, "", $block);
			if ($res === false) {
				_log("Error checking account address for send");
				return false;
			}
		}

		$bind = [
			":id"         => $this->id,
			":public_key" => $this->publicKey,
			":height"     => $height,
			":block"      => $block,
			":dst"        => $this->dst,
			":val"        => $this->val,
			":fee"        => $this->fee,
			":signature"  => $this->signature,
			":type"    => $this->type,
			":date"       => $this->date,
			":message"    => $this->msg,
		];
		$res = Transaction::insert($bind);
		if ($res != 1) {
			return false;
		}

		$type = $this->type;
		$res = true;
		if($type == TX_TYPE_REWARD) {
			$res = $res && Account::addBalance($this->dst, $this->val);
		} else if ($type == TX_TYPE_SEND || $type == TX_TYPE_MN_CREATE) {
			$res = $res && Account::addBalance($this->src, ($this->val + $this->fee)*(-1));
			$res = $res && Account::addBalance($this->dst, ($this->val));
		}
		if($res === false) {
			_log("Error updating balance for transaction ".$this->id);
			return false;
		}

		if ($type == TX_TYPE_MN_CREATE) {
			$dstPublicKey = Account::publicKey($this->dst);
			$res = Masternode::create($dstPublicKey, $height);
			if(!$res) {
				_log("Can not create masternode");
				return false;
			}
		}

		if ($type == TX_TYPE_MN_REMOVE) {
			$res = true;
			$res = $res && Account::addBalance($this->src, ($this->val + $this->fee)*(-1));
			$res = $res && Account::addBalance($this->dst, ($this->val));
			if($res === false) {
				_log("Error updating balance for transaction ".$this->id);
				return false;
			}
			$mn = Masternode::get($this->publicKey);
			if(!$mn) {
				_log("Masternode with public key {$this->publicKey} does not exists");
				return false;
			}
			$res = Masternode::delete($this->publicKey);
			if(!$res) {
				_log("Can not delete masternode with public key: ".$this->publicKey);
				return false;
			}
		}

		Mempool::delete($this->id);
		return true;
	}

    // hash the transaction's most important fields and create the transaction ID
	public function hash()
	{

		$base = $this->getSignatureBase();
		$base = $base ."-" . $this->signature;

		$hash = hash("sha256", $base);
		$hash = hex2coin($hash);
		_log("Transaction hash info=".$base." hash=$hash", 4);
		$this->id = $hash;
		return $hash;
	}

    // check the transaction for validity
    public function check($height = 0, $verify = false, &$error = null)
    {
        global $db;
        // if no specific block, use current

	    $current = Block::current();
		$last_height = $current['height'];

        if ($height === 0) {
            $height = $last_height;
        }
        $base = $this->getSignatureBase();

		try {

	        // the value must be >=0
	        if ($this->val <= 0) {
	            throw new Exception("{$this->val} - Value <= 0", 3);
	        }

	        // the fee must be >=0
	        if ($this->fee < 0) {
		        throw new Exception("{$this->fee} - Fee below 0", 3);
	        }

		    //genesis transaction
		    $mining_segments = REWARD_SCHEME['mining']['segments'];
		    $mining_segment_block = REWARD_SCHEME['mining']['block_per_segment'];
		    $launch_blocks = REWARD_SCHEME['launch']['blocks'];
		    $mining_end_block = ($mining_segments * $mining_segment_block) + $launch_blocks;
		    if($this->publicKey==GENESIS_DATA['public_key'] && $this->type==TX_TYPE_SEND && $height < $mining_end_block) {
			    throw new Exception("Genesis can not spend before locked height");
		    }

		    $fee = $this->val * TX_FEE;
		    $fee = num($fee);

	        // added fee does not match
	        if ($fee != $this->fee) {
		        throw new Exception("{$this->id} - Invalid fee ".json_encode($this));
	        }

		//check types
	    $type = $this->type;
		if(!in_array($type, [TX_TYPE_REWARD, TX_TYPE_SEND, TX_TYPE_MN_CREATE, TX_TYPE_MN_REMOVE])) {
			$error = "Invalid transaction type $type";
			_log($error);
			return false;
		}

	        if($this->type==TX_TYPE_REWARD) {
	            $res = $this->checkRewards($height);
	            if(!$res) {
		            if($height > UPDATE_2_BLOCK_CHECK_IMPROVED) {
			            throw new Exception("Transaction rewards check failed");
		            }
		        }
	        }


        if ($this->type==TX_TYPE_SEND || $this->type == TX_TYPE_MN_CREATE || $this->type == TX_TYPE_MN_REMOVE) {
	            // invalid destination address
	            if (!Account::valid($this->dst)) {
		            throw new Exception("{$this->id} - Invalid destination address");
	            }
	            $src = Account::getAddress($this->publicKey);
	            if($src==$this->dst) {
		            throw new Exception("{$this->id} - Invalid destination address");
	            }
	        }


	        // public key must be at least 15 chars / probably should be replaced with the validator function
	        if (strlen($this->publicKey) < 15) {
		        throw new Exception("{$this->id} - Invalid public key size");
	        }
	        // no transactions before the genesis
	        if ($this->date < GENESIS_TIME) {
		        throw new Exception("{$this->id} - Date before genesis");
	        }
	        // no future transactions
	        if ($this->date > time() + 86400) {
		        throw new Exception("{$this->id} - Date in the future");
	        }
	        $thisId = $this->id;
	        $id = $this->hash();
	        // the hash does not match our regenerated hash
	        if ($thisId != $id) {
		        throw new Exception("{$this->id} - $id - Invalid hash");
	        }

	        //verify the ecdsa signature
	        if (!Account::checkSignature($base, $this->signature, $this->publicKey)) {
		        throw new Exception("{$this->id} - Invalid signature - $base");
	        }


		} catch (Exception $e) {
			$error = $e->getMessage();
			_log("Transaction {$this->id} check failed: ".$error);
			return false;
		}

		try {

			if($this->type==TX_TYPE_MN_CREATE) {

				$startHeight = Block::getMnStartHeight();
				if ($height < Block::getMnStartHeight()) {
					throw new Exception("Masternode can not be created because of height. Start height is $startHeight, tx height is $height");
				}

				if(!$verify) {
					$res = Masternode::existsPublicKey($this->publicKey);
					$src = Account::getAddress($this->publicKey);
					if ($res) {
						//check amount to send
						$balance = Account::pendingBalance($src);
						if ($this->val > $balance - MN_COLLATERAL) {
							throw new Exception("Invalid amount to transfer form masternode. balance=$balance val=" . $this->val);
						}
					}

					$res = Masternode::existsPublicKey($this->publicKey);
					if ($res) {
						throw new Exception("Source public key {$this->publicKey} is already a masternode");
					}
					$dst = $this->dst;
					$dstPublicKey = Account::publicKey($dst);
					if (!$dstPublicKey) {
						throw new Exception("Destination address $dst is not verified!");
					}
					$res = Masternode::existsPublicKey($dstPublicKey);
					if ($res) {
						throw new Exception("Destination address $dst is already a masternode");
					}
				}
				if($this->val != MN_COLLATERAL) {
					throw new Exception("Invalid masternode collateral {$this->val}, must be ".MN_COLLATERAL);
				}
			}

			if($this->type==TX_TYPE_MN_REMOVE) {
				$startHeight = Block::getMnStartHeight();
				if($height < Block::getMnStartHeight()) {
					throw new Exception("Masternode can not be removed because of height. Start height is $startHeight, tx height is $height");
				}

				if(!$verify) {
					$masternode = Masternode::get($this->publicKey);
					if(!$masternode) {
						throw new Exception("Can not find masternode with public key ".$this->publicKey);
					}
					if($height < $masternode['height'] + MN_MIN_RUN_BLOCKS ) {
						throw new Exception("Masternode must run at least ".MN_MIN_RUN_BLOCKS." blocks. Created at block ". $masternode['height']. " check block=".$height);
					}
					$res = Masternode::existsPublicKey($this->publicKey);
					if(!$res) {
						throw new Exception("Source public key {$this->publicKey} is not masternode");
					}
					$dst = $this->dst;
					$dstPublicKey = Account::publicKey($dst);
					if($dstPublicKey) {
						$res = Masternode::existsPublicKey($dstPublicKey);
						if($res) {
							throw new Exception("Destination address $dst can not be masternode");
						}
					}
					$masternode = Masternode::get($this->publicKey);
					if(!$masternode) {
						throw new Exception("Can not find masternode with public key ".$this->publicKey);
					}
				}

				if($this->val != MN_COLLATERAL) {
					throw new Exception("Invalid masternode collateral {$this->val}, must be ".MN_COLLATERAL);
				}
			}

		} catch (Exception $e) {
			$error = $e->getMessage();
			_log($error);
			return false;
		}

        return true;
    }


    public function verify($height = 0, &$error = null)
    {
		return $this->check($height, true, $error);
    }

    function checkRewards($height) {
		$reward = Block::reward($height);
		$msg = $this->msg;
		if(empty($msg)) {
			_log("Reward transaction message missing", 5);
			return false;
		}
		if(!in_array($msg, ["nodeminer", "miner", "generator"]) &&
			!(substr($msg, 0, strlen("pool|")) == "pool|" && $height < UPDATE_4_NO_POOL_MINING)) {
			_log("Reward transaction invalid message: $msg",5);
			return false;
		}
		$miner = $reward['miner'];
		$generator = $reward['generator'];
		if($msg == "nodeminer") {
			$val_check = num($miner + $generator);
		} else if ($msg == "miner") {
			$val_check = num($miner);
		} else if ($msg == "generator") {
			$val_check = num($generator);
		} else if (substr($msg, 0, strlen("pool|")) == "pool|" && $height < UPDATE_4_NO_POOL_MINING) {
			$val_check = num($miner);
		}
		if(empty($val_check)) {
			_log("Reward transaction no value",5);
			return false;
		}
		if(num($this->val) != $val_check) {
			_log("Reward transaction not valid: val=".$this->val." val_check=$val_check", 5);
			return false;
		}
	    if (substr($msg, 0, strlen("pool|")) == "pool|" && $height < UPDATE_4_NO_POOL_MINING) {
	    	$arr = explode("|", $msg);
	    	$poolMinerAddress=$arr[1];
	    	$poolMinerAddressSignature=$arr[2];
		    $poolMinerPublicKey = Account::publicKey($poolMinerAddress);
		    if(empty($poolMinerPublicKey)) {
			    _log("Reward transaction not valid: not found public key for address $poolMinerAddress", 5);
			    return false;
		    }
		    $res = Account::checkSignature($poolMinerAddress, $poolMinerAddressSignature, $poolMinerPublicKey);
		    if(!$res) {
			    _log("Reward transaction not valid: address signature failed poolMinerAddress=$poolMinerAddress poolMinerAddressSignature=$poolMinerAddressSignature", 5);
			    return false;
		    }
	    }
		return true;
    }

	public function sign($private_key)
	{
		$base = $this->getSignatureBase();
		$signature = ec_sign($base, $private_key);
		$this->signature = $signature;
		return $signature;
	}

    public function getSignatureBase() {
    	$val = $this->val;
    	$fee = $this->fee;
    	$date = $this->date;
    	if(is_numeric($val)) {
    		$val = num($val);
	    }
    	if(is_numeric($fee)) {
		    $fee = num($fee);
	    }
    	if(!is_numeric($date)) {
		    $date = intval($date);
	    }
    	$parts = [];
    	$parts[]=$val;
    	$parts[]=$fee;
    	$parts[]=$this->dst;
    	$parts[]=$this->msg;
    	$parts[]=$this->type;
    	$parts[]=$this->publicKey;
    	$parts[]=$date;
	    $base = implode("-", $parts);
	    return $base;
    }

	public static function export($id)
	{
		global $db;
		$r = Mempool::getById($id);
		$r['date']=intval($r['date']);
		return $r;
	}

    // get the transaction data as array
    public static function get_transaction($id)
    {
        global $db;
        $current = Block::current();

        $x = $db->row("SELECT * FROM transactions WHERE id=:id", [":id" => $id]);

        if (!$x) {
            return false;
        }
	    $trans = self::process_tx($x, $current['height']);
        return $trans;
    }

    private static function process_tx($x, $height) {
	    $trans = [
		    "block"      => $x['block'],
		    "height"     => $x['height'],
		    "id"         => $x['id'],
		    "dst"        => $x['dst'],
		    "val"        => $x['val'],
		    "fee"        => $x['fee'],
		    "signature"  => $x['signature'],
		    "message"    => $x['message'],
		    "type"    => $x['type'],
		    "date"       => $x['date'],
		    "public_key" => $x['public_key'],
	    ];
	    $trans['confirmations'] = $height - $x['height'];

	    if ($x['type'] == TX_TYPE_REWARD) {
		    $trans['type_label'] = "mining";
	    } elseif ($x['type'] == TX_TYPE_SEND) {
		    $trans['type_label'] = "transfer";
	    } elseif ($x['type'] == TX_TYPE_MN_CREATE) {
		    $trans['type_label'] = "masternode create";
	    } elseif ($x['type'] == TX_TYPE_MN_REMOVE) {
		    $trans['type_label'] = "masternode remove";
	    } else {
		    $trans['type_label'] = "other";
	    }
	    return $trans;
    }

    // return the transactions for a specific block id or height
    public static function get_transactions($height = "", $id = "", $includeMiningRewards = false)
    {
        global $db;
        $current = Block::current();
        $height = san($height);
        $id = san($id);
        if (empty($id) && empty($height)) {
            return false;
        }
        $typeLimit = $includeMiningRewards ? TX_TYPE_REWARD : TX_TYPE_SEND;
        if (!empty($id)) {
            $r = $db->run("SELECT * FROM transactions WHERE block=:id AND type >= :type", [":id" => $id, ":type" => $typeLimit]);
        } else {
            $r = $db->run("SELECT * FROM transactions WHERE height=:height AND type >= :type", [":height" => $height, ":type" => $typeLimit]);
        }
        $res = [];
        foreach ($r as $x) {
	        $trans = self::process_tx($x, $current['height']);
            ksort($trans);
            $res[] = $trans;
        }
        return $res;
    }

    // get a specific mempool transaction as array
    public static function get_mempool_transaction($id)
    {
        global $db;
		$x = Mempool::getById($id);
        if (!$x) {
            return false;
        }
        $trans = [
            "block"      => $x['block'],
            "height"     => $x['height'],
            "id"         => $x['id'],
            "dst"        => $x['dst'],
            "val"        => $x['val'],
            "fee"        => $x['fee'],
            "signature"  => $x['signature'],
            "message"    => $x['message'],
            "type"    => $x['type'],
            "date"       => $x['date'],
            "public_key" => $x['public_key'],
        ];
        $trans['src'] = $x['src'];

        $trans['type_label'] = "mempool";
        $trans['confirmations'] = -1;
        ksort($trans);
        return $trans;
    }

    static function getRewardTransaction($dst,$date,$public_key,$private_key,$reward, $msg) {
	    $transaction = new Transaction($public_key,$dst,$reward,TX_TYPE_REWARD,$date,$msg);
	    $signature = $transaction->sign($private_key);
	    $transaction->hash();
	    return $transaction->toArray();
    }

    static function getForBlock($height) {
		return Transaction::get_transactions($height, "", true);
    }

	static function getById($id) {
		global $db;
		$x = $db->row("SELECT * FROM transactions WHERE id=:id", [":id" => $id]);
		return Transaction::getFromDbRecord($x);
	}


	static function getMempoolById($id) {
		return Transaction::get_mempool_transaction($id);
	}


    function addToMemPool(&$error) {
    	global $db;
	    $hash = $this->hash();

	    $max_txs = Block::max_transactions();
	    $mempool_size = Transaction::getMempoolCount();

	    if($mempool_size + 1 > $max_txs) {
		    $error = "Mempool full";
		    _log("Not added transaction to mempool because is full: max_txs=$max_txs mempool_size=$mempool_size");
		    return false;
	    }

	    if (!$this->verify(0, $err)) {
		    $error = "Transaction signature failed. Error: $err";
		    return false;
	    }
		$res = Mempool::existsTx($hash);
	    if ($res != 0) {
		    $error="The transaction is already in mempool";
		    return false;
	    }

	    $res = $db->single("SELECT COUNT(1) FROM transactions WHERE id=:id", [":id" => $hash]);
	    if ($res != 0) {
		    $error= "The transaction is already in a block";
		    return false;
	    }

	    $balance = $db->single("SELECT balance FROM accounts WHERE id=:id", [":id" => $this->src]);
	    if ($balance < $this->val + $this->fee) {
		    $error = "Not enough funds";
		    return false;
	    }

		$memspent = Mempool::getSourceMempoolBalance($this->src);
	    if ($balance - $memspent < $this->val + $this->fee) {
		    $error = "Not enough funds (mempool)";
		    return false;
	    }

		if($this->type == TX_TYPE_MN_CREATE) {
			$cnt = Mempool::getByDstAndType($this->dst, $this->type);
			if($cnt > 0) {
				$error = "Similar transaction already in mempool";
				return false;
			}
			$publicKey = Account::publicKey($this->dst);
			$res = Masternode::existsPublicKey($publicKey);
			if($res) {
				$error = "Masternode already exists";
				return false;
			}
		}

	    $this->add_mempool("local");
	    $hashp=escapeshellarg(san($hash));
	    $dir = dirname(dirname(__DIR__)) . "/cli";
	    system("php $dir/propagate.php transaction $hashp > /dev/null 2>&1  &");
	    return $hash;
    }

    static function getCount() {
    	global $db;
    	$sql="select count(*) as cnt from transactions";
		$row = $db->row($sql);
		return $row['cnt'];
    }

    static function getMempoolCount() {
		return Mempool::getSize();
    }

    static function getByAddress($address, $limit) {
	    $transactions = Account::getMempoolTransactions($address);
	    $transactions = array_merge($transactions, Account::getTransactions($address, $limit));
	    return $transactions;
    }

	static function getWalletTransactions($address, $dm) {
		return [
			'mempool'=>Account::getMempoolTransactions($address),
			'completed'=>Account::getTransactions($address, $dm)
		];
	}

    static function insert($bind) {
    	global $db;
	    $res = $db->run(
		    "INSERT into transactions 
    			(id, public_key, block,  height, dst, val, fee, signature, type, message, `date`)
    			values (:id, :public_key, :block, :height, :dst, :val, :fee, :signature, :type, :message, :date)
    			",
		    $bind
	    );
	    return $res;
    }


}
