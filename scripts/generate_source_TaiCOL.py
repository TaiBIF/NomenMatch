
# 產生NomenMatch Backbone
# 學名階層 要使用線上的db

import re
import pymysql
import pandas as pd
from datetime import datetime, timedelta, strftime
import json
import numpy as np

db_settings = {'host': '', 'port': '', 'user': '', 'password': '', 'db': ''}

rank_map = {
    1: 'Domain', 2: 'Superkingdom', 3: 'Kingdom', 4: 'Subkingdom', 5: 'Infrakingdom', 6: 'Superdivision', 7: 'Division', 8: 'Subdivision', 9: 'Infradivision', 10: 'Parvdivision', 11: 'Superphylum', 12:
    'Phylum', 13: 'Subphylum', 14: 'Infraphylum', 15: 'Microphylum', 16: 'Parvphylum', 17: 'Superclass', 18: 'Class', 19: 'Subclass', 20: 'Infraclass', 21: 'Superorder', 22: 'Order', 23: 'Suborder',
    24: 'Infraorder', 25: 'Superfamily', 26: 'Family', 27: 'Subfamily', 28: 'Tribe', 29: 'Subtribe', 30: 'Genus', 31: 'Subgenus', 32: 'Section', 33: 'Subsection', 34: 'Species', 35: 'Subspecies', 36:
    'Nothosubspecies', 37: 'Variety', 38: 'Subvariety', 39: 'Nothovariety', 40: 'Form', 41: 'Subform', 42: 'Special Form', 43: 'Race', 44: 'Stirp', 45: 'Morph', 46: 'Aberration', 47: 'Hybrid Formula',
    48: 'Subrealm', 49: 'Realm'}

query = """
            WITH base_query AS (SELECT taxon_id, GROUP_CONCAT(name_c ORDER BY is_primary DESC SEPARATOR ',') AS common_name_c
            FROM api_common_name GROUP BY taxon_id)
            SELECT t.taxon_id, t.taxon_id, concat_ws(' ', tn.name, an.name_author), t.taxon_id, t.taxon_id, 
            bq.common_name_c, t.rank_id, att.lin_path, tn.name, atu.status 
            FROM api_taxon_usages atu 
            JOIN api_taxon t ON atu.taxon_id = t.taxon_id
            JOIN taxon_names tn ON atu.taxon_name_id = tn.id
            JOIN api_names an ON atu.taxon_name_id = an.taxon_name_id
            LEFT JOIN api_taxon_tree att ON atu.taxon_id = att.taxon_id
            LEFT JOIN base_query bq ON bq.taxon_id = t.taxon_id
            WHERE t.is_deleted != 1
        """

conn = pymysql.connect(**db_settings)
with conn.cursor() as cursor:
    cursor.execute(query)
    df = cursor.fetchall()
    df = pd.DataFrame(df, columns=['namecode','accepted_namecode','scientific_name','name_url_id','accepted_url_id',
    'common_name_c', 'rank', 'path', 'simple_name', 'name_status'])


df = df.drop_duplicates().reset_index(drop=True)


query = f"SELECT t.rank_id, tn.name, t.taxon_id \
        FROM api_taxon t \
        JOIN taxon_names tn ON t.accepted_taxon_name_id = tn.id \
        WHERE  t.rank_id IN (3,12,18,22,26,30)"
with conn.cursor() as cursor:
    cursor.execute(query)
    path_df = cursor.fetchall()
    path_df = pd.DataFrame(path_df, columns=['rank','simple_name','taxon_id'])


# higher taxa
for i in df.index:
    if i % 1000 == 0:
        print(i)
    row = df.iloc[i]
    if path := row.path:
        path = path.split('>')
        # 拿掉自己
        path = [p for p in path if p != row.namecode]
        # 3,12,18,22,26,30,34 
        if path:
            data = []
            results = path_df[path_df.taxon_id.isin(path)&path_df['rank'].isin([3,12,18,22,26,30])]
            results =  results.reset_index(drop=True)
            for r in results.index:
                rr = results.iloc[r]
                r_rank_id = rr['rank']
                df.loc[i, f'{rank_map[r_rank_id].lower()}'] = rr['simple_name']

# rank_id to rank
df['rank'] = df['rank'].apply(lambda x: rank_map[x])

df = df.replace({np.nan: '', None: ''})




"""
	/**
	 * 0 namecode taxonUUID
	 * 1 accepted_namecode taxonUUID
	 * 2 scientific_name scientificName
	 * 3 name_url_id taxonUUID
	 * 4 accepted_url_id taxonUUID
	 * 5 common_name_c vernacularName
	 * 6 taxon_rank taxonRank
	 * 7 genus
	 * 8 family
	 * 9 order
	 * 10 class
	 * 11 phylum
	 * 12 kingdom
	 * 13 simple_name simplifiedScientificName
     * 14 name_status
	 */
"""

# 欄位順序
df = df[['namecode', 'accepted_namecode', 'scientific_name', 'name_url_id', 'accepted_url_id', 'common_name_c', 
'rank', 'genus', 'family', 'order', 'class', 'phylum', 'kingdom', 'simple_name', 'name_status']]

today = datetime.today()

today_str = today.strftime("%Y%m%d")


df = df.replace({np.nan: None})

# df.to_csv(f'source_taicol_{today_str}.csv', sep='\t', header=None, index=False)

df.to_csv(f'./source-data/source_taicol_{today_str}.csv', sep='\t', header=None, index=False)

source = pd.read_table('./source-data/sources.csv', sep='\t', header=None)
# id 不可動
# name
source.loc[source[0]=='taicol_2',1] = 'TaiCOL' 
# url_base
source.loc[source[0]=='taicol_2',2] = 'https://web-staging.taicol.tw/taxon/'
# citation
source.loc[source[0]=='taicol_2',3] = 'K. F. Chung, K. T. Shao, Catalogue of life in Taiwan. Web electronic publication. version 2023'
# url
source.loc[source[0]=='taicol_2',4] = 'https://web-staging.taicol.tw'
# version 下載檔案上的日期
source.loc[source[0]=='taicol_2',5] = '2023-03-28'

source.to_csv('./source-data/sources.csv', sep='\t', header=None, index=None)



