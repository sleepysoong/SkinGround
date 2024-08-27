<?php

namespace naeng\SkinGround;

use kim\present\sqlcore\SqlPluginTrait;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\promise\PromiseResolver;
use pocketmine\scheduler\AsyncTask;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\Server;
use pocketmine\utils\SingletonTrait;
use SOFe\AwaitGenerator\Await;
use Symfony\Component\Filesystem\Path;
use CURLFile;
use Exception;
use Generator;

class SkinGround extends PluginBase implements Listener{

    use SqlPluginTrait;
    use SingletonTrait;

    private static TaskScheduler $scheduler;

    /** @var PromiseResolver[] */
    private array $promise;
    
    private array $url = [];

    public function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->initSql();
        if($this->getConfig()->get("key", "") === ""){
            $this->getConfig()->set("key", "인증 키를 입력하세요");
            $this->getConfig()->save();
            throw new Exception("인증 키가 설정되지 않았습니다");
        }
        if($this->getConfig()->get("url", "") === ""){
            $this->getConfig()->set("url", "http://127.0.0.1:5000/upload");
            $this->getConfig()->save();
            throw new Exception("업로드 링크가 설정되지 않았습니다");
        }
    }

    public function onLoad() : void{
        self::setInstance($this);
    }

    public function getPromise(int $xuid) : ?PromiseResolver{
        return $this->promise[$xuid] ?? null;
    }

    public function handlePromise(int $xuid, ?string $result) : void{
        if($result !== null){
            $this->url[$xuid] = $result;
            Await::g2c($this->conn->asyncChange("set", ["xuid" => $xuid, "url" => $result]));
        }
        ($this->promise[$xuid])($result);
        unset($this->promise[$xuid]);
    }

    public function get(Player $player) : Generator{
        $xuid = $player->getXuid();
        if(isset($this->url[$xuid])){
            return $this->url[$xuid];
        }
        $url = (yield from $this->conn->asyncSelect("get", ["xuid" => $xuid]))[0]["url"] ?? null;
        if($url !== null){
            $this->url[$xuid] = $url;
            yield from $this->conn->asyncChange("set", ["xuid" => $xuid, "url" => $url]);
            return $url;
        }
        return yield from Await::promise(function($resolve) use($xuid, $player){
            $this->promise[$xuid] = $resolve;
            $this->upload($xuid, $player->getSkin()->getSkinData(), $this->getDataFolder());
        });
    }

    public function getCashed(int $xuid) : ?string{
        return $this->url[$xuid] ?? null;
    }

    protected function upload(int $xuid, string $skinData, string $path) : void{
        $url = $this->getConfig()->get("url", "");
        $key = $this->getConfig()->get("key", "");
        Server::getInstance()->getAsyncPool()->submitTask(new class($skinData, $xuid, $path, $key, $url) extends AsyncTask{
            public function __construct(
                private readonly string $skinData,
                private readonly int $xuid,
                private readonly string $path,
                private readonly string $key,
                private readonly string $url
            ){}
            public function saveSkinPng() : void{
                $height = 64;
                $width = 64;
                switch(strlen($this->skinData)){
                    case 64 * 32 * 4:
                        $height = 32;
                        $width = 64;
                        break;
                    case 64 * 64 * 4:
                        $height = 64;
                        $width = 64;
                        break;
                    case 128 * 64 * 4:
                        $height = 64;
                        $width = 128;
                        break;
                    case 128 * 128 * 4:
                        $height = 128;
                        $width = 128;
                        break;
                }
                $img = imagecreatetruecolor($width, $height);
                imagealphablending($img, false);
                imagesavealpha($img, true);
                $index = 0;
                for($y = 0; $y < $height; ++$y){
                    for($x = 0; $x < $width; ++$x){
                        $list = substr($this->skinData, $index, 4);
                        $r = ord($list[0]);
                        $g = ord($list[1]);
                        $b = ord($list[2]);
                        $a = 127 - (ord($list[3]) >> 1);
                        $index += 4;
                        $color = imagecolorallocatealpha($img, $r, $g, $b, $a);
                        imagesetpixel($img, $x, $y, $color);
                    }
                }
                imagepng($img, Path::join($this->path, "temp-{$this->xuid}.png"));
                imagedestroy($img);
            }
            public function getPastelBackgroundColor($image, $x, $y, $width, $height){
                $rTotal = $gTotal = $bTotal = $total = 0;
                for($i = $x; $i < $x + $width; $i++){
                    for($j = $y; $j < $y + $height; $j++){
                        $rgb = imagecolorat($image, $i, $j);
                        $r = ($rgb >> 16) & 0xFF;
                        $g = ($rgb >> 8) & 0xFF;
                        $b = $rgb & 0xFF;
                        $rTotal += $r;
                        $gTotal += $g;
                        $bTotal += $b;
                        $total++;
                    }
                }
                return [
                    round(($rTotal / $total + 255)/2),
                    round(($gTotal / $total + 255)/2),
                    round(($bTotal / $total + 255)/2)
                ];
            }
            public function saveWallpaper() : void{
                $skin = imagecreatefrompng(Path::join($this->path, "temp-{$this->xuid}.png"));
                $face = imagecreatetruecolor(8, 8);
                imagecopy($face, $skin, 0, 0, 8, 8, 8, 8);
                $width = 1920;
                $height = 1080;
                $background = imagecreatetruecolor($width, $height);
                $backgroundColor = $this->getPastelBackgroundColor($skin, 8, 8, 8, 8);
                imagefill($background, 0, 0, imagecolorallocate($background, $backgroundColor[0], $backgroundColor[1], $backgroundColor[2]));
                $faceResized = imagecreatetruecolor(500, 500);
                imagecopyresized($faceResized, $face, 0, 0, 0, 0, 500, 500, 8, 8);
                imagecopy($background, $faceResized, ($width - 500) / 2, ($height - 500) / 2, 0, 0, 500, 500);
                imagepng($background, Path::join($this->path, "wallpaper-{$this->xuid}.png"));
                imagedestroy($background);
                imagedestroy($skin);
                imagedestroy($face);
                imagedestroy($faceResized);
            }
            public function upload() : void{
                $path = Path::join($this->path, "wallpaper-{$this->xuid}.png");
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $this->url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, [
                    "file" => new CURLFile($path, mime_content_type($path), basename($path)),
                    "key" => $this->key
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: multipart/form-data'
                ));
                $response = curl_exec($ch);
                if($response === false){
                    $this->setResult(curl_error($ch));
                    curl_close($ch);
                    return;
                }
                curl_close($ch);
                $this->setResult(json_decode($response, true));
            }
            public function onRun() : void{
                $this->saveSkinPng();
                $this->saveWallpaper();
                $this->upload();
            }
            public function onCompletion() : void{
                $result = $this->getResult();
                if(!is_array($result)){
                    SkinGround::getInstance()->getLogger()->error($result ?? "url을 다시 한 번 확인해주세요");
                    $result = null;
                }elseif(isset($result["error"])){
                    SkinGround::getInstance()->getLogger()->error(json_encode($result));
                    $result = null;
                }elseif(isset($result["file_link"])){
                    $result = $result["file_link"] . "?key=" . urlencode($this->key);
                }else{
                    $result = null;
                }
                SkinGround::getInstance()->handlePromise($this->xuid, $result);
            }
        });
    }

    /*
    public function handlePlayerJoinEvent(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        Await::f2c(function() use($player){
            var_dump(yield from $this->get($player));
        });
    }
    */

}
