# VP-DEMO-002 — Simplificação da experiência de avaliação

**Objetivo:** substituir a proposta de infraestrutura DEMO independente por uma experiência de avaliação utilizando o cadastro SaaS e o período de trial já existentes no VetorPet.

## Diretrizes

1. Auditar o fluxo atual de cadastro, criação de tenant e assinatura trial de 14 dias.
2. Reutilizar a infraestrutura SaaS existente, sem criar containers ou bancos individuais por visitante.
3. Garantir isolamento completo entre tenants em todas as operações web, API, arquivos, relatórios e processos assíncronos.
4. Corrigir, em ambiente de desenvolvimento, situações em que ausência de tenant possa permitir consultas sem isolamento ou privilégios administrativos indevidos.
5. Avaliar a criação opcional de dados fictícios exclusivamente no tenant recém-cadastrado.
6. Garantir que os dados fictícios possam ser excluídos sem afetar registros reais.
7. Preservar o funcionamento atual dos planos, assinaturas e limites comerciais.
8. Não habilitar pagamentos, cobranças, mensagens ou integrações reais a partir dos dados demonstrativos.
9. Ocultar o VetorPest integralmente das contas destinadas exclusivamente ao VetorPet.
10. Revisar a experiência de cadastro, oferecendo um fluxo simples para distribuidores pet e veterinários.

## Validação obrigatória

Executar testes em banco isolado de desenvolvimento, incluindo:

- Criação de dois tenants diferentes.
- Tentativas de acesso cruzado entre empresas.
- Cadastro e manipulação de dados de exemplo.
- Verificação de permissões administrativas.
- Expiração do período de avaliação.
- Exclusão segura dos dados fictícios.
- Preservação dos dados de clientes reais.
- Regressão das funcionalidades existentes.

Entregar relatório `executed.md` com evidências e resultados.

**Não realizar deploy, não modificar dados de produção e não liberar publicamente as alterações sem homologação.**
