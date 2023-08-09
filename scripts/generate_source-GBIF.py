import pandas as pd
import numpy as np
from datetime import date

# https://www.gbif.org/dataset/d7dddbf4-2cf0-4f39-9b2a-bb099caae36c

df = pd.read_table('/Users/taibif/Documents/04-TaiCOL/GBIF_20230327/Taxon.tsv', sep='\t', 
    usecols=['taxonID', 'acceptedNameUsageID','scientificName','taxonRank',
              'parentNameUsageID','canonicalName',
              'kingdom','phylum','class','order','family','genus','taxonomicStatus'])
# 6921441

df['common_name_c'] = None

df = df.replace({np.nan: None})

df.loc[df['canonicalName'].isnull(), 'canonicalName'] = df.loc[df['canonicalName'].isnull(), 'scientificName']

df['acceptedNameUsageID']= df['acceptedNameUsageID'].astype(float).astype('Int64')

df = df[['taxonID','acceptedNameUsageID','scientificName','taxonID','acceptedNameUsageID', 
         'common_name_c', 'taxonRank', 'genus', 'family', 'order', 'class', 'phylum', 'kingdom', 'canonicalName','taxonomicStatus']]

# test = df[6921336:6921339]


# test['acceptedNameUsageID'].astype(int) # raises ValueError
df = df.replace({np.nan: None})


today = date.today()
today_str = today.strftime("%Y%m%d")

df.to_csv(f'../source-data/source_gbif_{today_str}.csv', sep='\t', header=None, index=False)



source = pd.read_table('../../source-data/sources.csv', sep='\t', header=None)
# source = source.append({0: 'gbif'}, ignore_index=True)
# id 不可動
# name
source.loc[source[0]=='gbif',1] = 'GBIF' 
# url_base
source.loc[source[0]=='gbif',2] = 'http://api.gbif.org/v1/species/'
# citation
source.loc[source[0]=='gbif',3] = 'GBIF Secretariat (2022). GBIF Backbone Taxonomy. Checklist dataset https://doi.org/10.15468/39omei accessed via GBIF.org on 2023-03-27.'
# url
source.loc[source[0]=='gbif',4] = 'https://www.gbif.org/dataset/d7dddbf4-2cf0-4f39-9b2a-bb099caae36c'
# version 下載檔案上的日期
source.loc[source[0]=='gbif',5] = '2023-03-27'

source.to_csv('../../source-data/sources.csv', sep='\t', header=None, index=None)

