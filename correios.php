<?php

namespace App\Services\Shared;

use Exception;
use Correios;
use Config;
use App\Library\phpQuery\PhpQuery as phpQuery;

class CorreioService
{
    //RASTREAR OBJETO
    public function rastreio($codigo){

        $url = 'http://www2.correios.com.br/sistemas/rastreamento/resultado_semcontent.cfm';

        $data = array(
            "Objetos" => $codigo
        );

        $ch = curl_init($url);

        curl_setopt ($ch, CURLOPT_POST, true);
        curl_setopt ($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt ($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true);

        $html = curl_exec($ch);

        curl_close($ch);

        phpQuery::newDocumentHTML($html, $charset = 'utf-8');

        $rastreamento = array();
        $c            = 0;

        foreach (phpQuery::pq('tr') as $tr)
        {
            $c++;
            if (count(phpQuery::pq($tr)->find('td')) == 2)
            {
                list($data, $hora, $local) = explode("<br>", phpQuery::pq($tr)->find('td:eq(0)')->html());
                list($status, $encaminhado) = explode("<br>", phpQuery::pq($tr)->find('td:eq(1)')->html());

                $rastreamento[] = array('data' => trim($data) . " " . trim($hora), 'local' => trim($local), 'status' => trim(strip_tags($status)));

                if (trim($encaminhado))
                {
                    $rastreamento[count($rastreamento) - 1]['encaminhado'] = trim($encaminhado);
                }
            }
        }

        if (!count($rastreamento))
            return false;

        return $rastreamento;
    }
    
    //BUSCA ENDEREÇO A PARTIR DE UM CEP
    public function getEnderecoByCep($cep){
       
        $url = 'http://www.buscacep.correios.com.br/sistemas/buscacep/resultadoBuscaCepEndereco.cfm';

        $data = array(
            'relaxation' => $cep,
            'tipoCEP'    => 'ALL',
            'semelhante'    => 'N',
        );

        $ch = curl_init($url);

        curl_setopt ($ch, CURLOPT_POST, true);
        curl_setopt ($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt ($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true);

        $html = curl_exec($ch);

        if ($html === false)
            throw new Exception(curl_error($ch), curl_errno($ch));

        curl_close($ch);
        
        phpQuery::newDocumentHTML($html, $charset = 'ISO-8859-1');

        $pq_form  = phpQuery::pq('');
        
        $pesquisa = array();
        if(phpQuery::pq('.tmptabela')){
            $linha = 0;
            foreach (phpQuery::pq('.tmptabela tr') as $pq_div)
            {
                if($linha){
                    $itens = array();
                    foreach (phpQuery::pq('td', $pq_div) as $pq_td){
                        $children = $pq_td->childNodes;
                        $innerHTML = '';
                        foreach ($children as $child) {
                            $innerHTML .= $child->ownerDocument->saveXML( $child );
                        }
                        $texto = preg_replace("/&#?[a-z0-9]+;/i","",$innerHTML);
                        $itens[] = trim( $texto );
                    }
                    $dados = array();
                    $dados['logradouro'] = trim($itens[0]);
                    $dados['bairro'] = trim($itens[1]);
                    $dados['cidade/uf'] = trim($itens[2]);
                    $dados['cep'] = trim($itens[3]);

                    $dados['cidade/uf'] = explode('/', $dados['cidade/uf']);

                    $dados['cidade'] = trim($dados['cidade/uf'][0]);

                    $dados['estado'] = trim($dados['cidade/uf'][1]);

                    unset($dados['cidade/uf']);

                    $pesquisa = $dados;
                }

                $linha++;
            }
        }

        return $pesquisa;

    }

    //BUSCA FRETE
	public function getFrete($transporte, $produto, $cep_destino){

        if(Config::get('loja.calculoFreteCorreios') == 'apiCorreios'){

            return $this->getApiCorreios($transporte, $produto, $cep_destino);
        }
    }

    //BUSCA FRETE DA API DOS CORREIOS
    private function getApiCorreios($transporte, $produto, $cep_destino){

        $array['nCdEmpresa'] = ($transporte['codAdministrativo']) ? $transporte['codAdministrativo'] : '';
        $array['sDsSenha'] = ($transporte['senha']) ? $transporte['senha'] : '';
        $array['sCepOrigem'] = str_replace("-", "", $transporte['cepOrigem']);
        $array['sCepDestino'] = str_replace("-", "", $cep_destino);
        $array['nVlPeso'] = bcdiv($produto['peso'], '1000', 3);
        $array['nCdFormato'] = 1;
        $array['nVlComprimento'] = $produto['profundidade'];
        $array['nVlAltura'] = $produto['altura'];
        $array['nVlLargura'] = $produto['largura'];
        $array['sCdMaoPropria'] = 'N';
        $array['nVlValorDeclarado'] = 0;
        $array['sCdAvisoRecebimento'] = 'S';
        $array['nCdServico'] = $transporte['codigo'];
        $array['nVlDiametro'] = 0;
        $array['StrRetorno'] = 'xml';
        $array['nIndicaCalculo'] = 3;

        $url = 'http://ws.correios.com.br/calculador/CalcPrecoPrazo.aspx?';
                
        //CRIA A URL (EX: valor=10&prazo=2)
        $url = $url . http_build_query($array);
 
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/xml; charset=utf-8']);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($curl);

        if ($result === false)
            throw new Exception(curl_error($curl), curl_errno($curl));
        
        curl_close($curl);

        $ar = simplexml_load_string($result);

        if($ar->cServico->Erro != '0')
           return ['erro' => $this->getMsgErro($ar->cServico->Erro), 'valor'=>0, 'prazo'=>0, 'PrazoEntrega'=>0];

        $data = (array) $ar->cServico;

        $res['nome']  = $transporte['nome'];
        $res['valor'] = bcadd(floatval($data['Valor']), $transporte['valorAdicional'], 2);
        $res['prazo'] = bcadd(intval($data['PrazoEntrega']), $transporte['prazoAdicional']);
        $res['erro'] = (isset($data['erro'])) ? $data['erro'] : '';

        return $res;

    }

    private function getMsgErro($cod){

        $res = null;

        switch ($cod) {
            case '-1':
                $res = 'Código de serviço inválido';
                break;
            case '-2':
                $res = 'CEP de origem inválido';
                break;
            case '-3':
                $res = 'CEP de destino inválido';
                break;
            case '-4':
                $res = 'Peso excedido';
                break;
            case '-6':
                $res = 'Serviço indisponível para o CEP informado';
                break;	
            case '-11':
                $res = 'Verificar Comprimento, altura e largura do produto';
                break;
            case '-12':
                $res = 'Comprimento inválido do produto';
                break;		
            case '-13':
                $res = 'Largura inválida do produto';
                break;
            case '-14':
                $res = 'Altura inválida do produto';
                break;
            case '-15':
                $res = 'O comprimento não pode ser maior que 105 cm';
                break;
            case '-16':
                $res = 'A largura não pode ser maior que 105 cm';
                break;
            case '-17':
                $res = 'A altura não pode ser maior que 105 cm';
                break;
            case '-18':
                $res = 'A altura não pode ser inferior a 2 cm';
                break;	
            case '-20':
                $res = 'A largura não pode ser inferior a 11 cm';
                break;	
            case '-22':
                $res = 'O comprimento não pode ser inferior a 16 cm';
                break;	
            case '-23':
                $res = 'A soma do comprimento + largura + altura não deve superar a 200 cm';
                break;	
            case '-24':
                $res = 'Comprimento inválido';
                break;	
            case '-26':
                $res = 'Informe o comprimento';
                break;	
            case '-28':
                $res = 'O comprimento não pode ser maior que 105 cm';
                break;	
            case '-30':
                $res = 'O comprimento não pode ser inferior a 18 cm';
                break;	
            case '-31':
                $res = 'O diâmetro não pode ser inferior a 5 cm';
                break;	
            case '-33':
                $res = 'Sistema dos correios fora do ar. Favor tentar mais tarde';
                break;
            case '-34':
                $res = 'Código Administrativo ou Senha inválidos';
                break;
            case '-35':
                $res = 'Senha incorreta';
                break;

        }

        return $res;


    }




}